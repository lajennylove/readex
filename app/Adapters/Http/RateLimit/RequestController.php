<?php

namespace App\Adapters\Http\RateLimit;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/**
 * Decides when each source request may go out. All state lives in SQLite so the UI
 * thread, async tasks and the queue worker share one budget. Time comes from Carbon
 * and waiting from Sleep, so tests drive both with Carbon::setTestNow + Sleep::fake.
 */
final class RequestController
{
    /** @var array<string, int|float> */
    private array $tuning;

    public function __construct(private readonly LimitsTable $limits)
    {
        $this->tuning = config('source.controller');
    }

    public function acquire(Call $call, Priority $priority, float $maxWait): Slot|Busy
    {
        $resolution = $this->limits->resolve($call, $priority);
        $deadline = $this->now() + $maxWait;

        while (true) {
            $result = DB::transaction(fn () => $this->tryTake($call, $priority, $resolution));

            if ($result instanceof Slot || $result->retryAt > $deadline) {
                return $result;
            }

            Sleep::usleep(max(1000, (int) ceil($result->secondsFrom($this->now()) * 1_000_000)));
        }
    }

    public function record(Slot $slot, ?Response $response): void
    {
        DB::transaction(function () use ($slot, $response) {
            $now = $this->now();
            $status = $response?->status();
            $banned = $response !== null && $this->isBan($response);

            $this->countUsage($slot->usageKey(), $now, $status === 429, $banned);

            if ($slot->family !== null && $banned) {
                $this->ban($slot->family, $now);

                return;
            }

            if ($slot->family !== null && $status === 429) {
                $this->throttle($slot->family, $response, $now);

                return;
            }

            if ($slot->isProbe && $response !== null) {
                $this->clearBan($slot->family, $now);
            }

            if ($slot->endpoint !== null && $response !== null) {
                $this->syncHeaders($slot->endpoint, $response, $now);
            }
        });
    }

    public function status(): ConnectionStatus
    {
        $now = $this->now();
        $minute = (int) floor($now / 60);
        $family = $this->peek('family:'.config('source.status_family'));
        $global = $this->peek('global');

        $lastMinute = (int) DB::table('api_usage')->where('minute', $minute)->sum('requests');
        $lastHour = (int) DB::table('api_usage')->where('minute', '>', $minute - 60)->sum('requests');

        if ($family?->isBlocked($now)) {
            return new ConnectionStatus(
                ConnectionState::Paused,
                CarbonImmutable::createFromTimestamp($family->blockedUntil),
                $family->strikes > 0,
                $lastMinute,
                $lastHour,
            );
        }

        $slowed = $global !== null && $global->refillPerSec < $this->limits->bucket('global')->refillPerSec();

        return new ConnectionStatus(
            $slowed ? ConnectionState::Slowed : ConnectionState::Ok,
            null,
            false,
            $lastMinute,
            $lastHour,
        );
    }

    private function tryTake(Call $call, Priority $priority, Resolution $resolution): Slot|Busy
    {
        $now = $this->now();
        $family = null;

        if ($resolution->family !== null) {
            $family = $this->load('family:'.$resolution->family, $now);

            if ($family->isBlocked($now)) {
                return new Busy($family->blockedUntil, $family->strikes > 0 ? BusyReason::Banned : BusyReason::Throttled);
            }
        }

        $rows = array_map(fn (string $key) => $this->load($key, $now), $resolution->buckets);
        $wait = max(array_map(fn (BucketRow $row) => $row->waitFor($now), $rows));

        if ($wait > 0) {
            return new Busy($now + $wait, BusyReason::Saturated);
        }

        foreach ($rows as $row) {
            $row->tokens -= 1.0;
            $this->save($row);
        }

        $isProbe = $family !== null && $family->probing;

        if ($isProbe) {
            $family->blockedUntil = $now + $this->tuning['probe_timeout_seconds'];
            $this->save($family);
        }

        return new Slot($call, $priority, $resolution->buckets, $resolution->endpoint, $resolution->family, $isProbe);
    }

    private function ban(string $family, float $now): void
    {
        $row = $this->load('family:'.$family, $now);
        $seconds = min($this->tuning['ban_max_seconds'], $this->tuning['ban_base_seconds'] * 2 ** $row->strikes);

        $row->blockedUntil = $now + $seconds;
        $row->strikes++;
        $row->probing = true;
        $this->save($row);
    }

    private function clearBan(string $family, float $now): void
    {
        $row = $this->load('family:'.$family, $now);
        $row->blockedUntil = null;
        $row->strikes = 0;
        $row->probing = false;
        $this->save($row);
    }

    private function throttle(string $family, Response $response, float $now): void
    {
        $until = $this->retryAt($response, $now);

        $row = $this->load('family:'.$family, $now);
        $row->blockedUntil = max($row->blockedUntil ?? 0.0, $until);
        $this->save($row);

        foreach ($this->limits->adaptiveBuckets() as $def) {
            $bucket = $this->load($def->key, $now);
            $bucket->refillPerSec = max(
                $def->refillPerSec() * $this->tuning['throttle_min_fraction'],
                $bucket->refillPerSec * $this->tuning['throttle_factor'],
            );
            $bucket->tokens = 0.0;
            $bucket->updatedAt = $until;
            $bucket->adjustedAt = $until;
            $this->save($bucket);
        }
    }

    private function syncHeaders(string $key, Response $response, float $now): void
    {
        $remaining = $this->intHeader($response, 'X-RateLimit-Remaining');

        if ($remaining === null) {
            return;
        }

        $def = $this->limits->bucket($key);
        $row = $this->load($key, $now);
        $limit = $this->intHeader($response, 'X-RateLimit-Limit');

        if ($limit !== null && $limit !== ($row->learnedLimit ?? $def->limit)) {
            $row->learnedLimit = $limit;
            $row->capacity = $def->capacityFor($limit);
            $row->refillPerSec = $def->perSeconds > 0 ? $row->capacity / $def->perSeconds : 0.0;
        }

        $margin = max(0.0, ($row->learnedLimit ?? $def->limit ?? $row->capacity) - $row->capacity);
        $row->tokens = min($row->tokens, max(0.0, $remaining - $margin));

        if ($remaining <= $margin) {
            $reset = $this->intHeader($response, 'X-RateLimit-Retry-After');
            $row->blockedUntil = $reset !== null && $reset > $now ? (float) $reset : $now + $def->perSeconds;
        }

        $this->save($row);
    }

    private function load(string $key, float $now): BucketRow
    {
        $def = $this->limits->bucket($key);
        $record = DB::table('rate_buckets')->where('key', $key)->first();

        if ($record === null) {
            $row = BucketRow::fresh($def, $now);
            DB::table('rate_buckets')->insert($row->toDb());

            return $row;
        }

        $row = BucketRow::fromDb($record);
        $row->refill($now);

        if ($def->adaptive && $row->refillPerSec < $def->refillPerSec() && $now > $row->adjustedAt) {
            $steps = (int) floor(($now - $row->adjustedAt) / $this->tuning['recover_every_seconds']);

            if ($steps > 0) {
                $row->refillPerSec = min(
                    $def->refillPerSec(),
                    $row->refillPerSec + $steps * $def->refillPerSec() * $this->tuning['recover_fraction'],
                );
                $row->adjustedAt += $steps * $this->tuning['recover_every_seconds'];
            }
        }

        return $row;
    }

    private function peek(string $key): ?BucketRow
    {
        $record = DB::table('rate_buckets')->where('key', $key)->first();

        return $record === null ? null : BucketRow::fromDb($record);
    }

    private function save(BucketRow $row): void
    {
        DB::table('rate_buckets')->where('key', $row->key)->update($row->toDb());
    }

    private function countUsage(string $bucket, float $now, bool $throttled, bool $banned): void
    {
        $minute = (int) floor($now / 60);

        DB::table('api_usage')->upsert(
            ['minute' => $minute, 'bucket' => $bucket, 'requests' => 1, 'http_429' => (int) $throttled, 'http_403' => (int) $banned],
            ['minute', 'bucket'],
            [
                'requests' => DB::raw('requests + 1'),
                'http_429' => DB::raw('http_429 + '.(int) $throttled),
                'http_403' => DB::raw('http_403 + '.(int) $banned),
            ],
        );

        DB::table('api_usage')->where('minute', '<', $minute - $this->tuning['usage_keep_minutes'])->delete();
    }

    /** A 403 that is not a normal JSON API error is the temporary IP ban. */
    private function isBan(Response $response): bool
    {
        if ($response->status() !== 403) {
            return false;
        }

        $json = json_decode($response->body(), true);

        return ! (is_array($json) && ($json['result'] ?? null) === 'error');
    }

    private function retryAt(Response $response, float $now): float
    {
        $resetAt = $this->intHeader($response, 'X-RateLimit-Retry-After');
        if ($resetAt !== null && $resetAt > $now) {
            return (float) $resetAt;
        }

        $after = $this->intHeader($response, 'Retry-After');
        if ($after !== null && $after > 0) {
            return $now + $after;
        }

        return $now + $this->tuning['throttle_default_seconds'];
    }

    private function intHeader(Response $response, string $name): ?int
    {
        $value = $response->header($name);

        return is_numeric($value) ? (int) $value : null;
    }

    private function now(): float
    {
        return (float) Carbon::now()->format('U.u');
    }
}
