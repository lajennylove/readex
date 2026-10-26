<?php

use App\Adapters\Http\RateLimit\Busy;
use App\Adapters\Http\RateLimit\BusyReason;
use App\Adapters\Http\RateLimit\Call;
use App\Adapters\Http\RateLimit\Priority;
use App\Adapters\Http\RateLimit\RequestController;
use App\Adapters\Http\RateLimit\Slot;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

const T0 = 1_792_756_800.0; // 2026-10-23 12:00:00 UTC

beforeEach(function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(T0));
    Sleep::fake(syncWithCarbon: true);
    $this->rc = app(RequestController::class);
});

function api(string $path = '/manga'): Call
{
    return Call::get('https://api.mangadex.org/api'.$path);
}

function clock(): float
{
    return (float) Carbon::now()->format('U.u');
}

function advance(float $seconds): void
{
    Carbon::setTestNow(Carbon::now()->addMicroseconds((int) round($seconds * 1_000_000)));
}

function reply(int $status, array $headers = [], string $body = '{"result":"ok"}'): Response
{
    return new Response(new Psr7Response($status, $headers, $body));
}

function tokens(string $key): float
{
    return (float) DB::table('rate_buckets')->where('key', $key)->value('tokens');
}

describe('token buckets', function () {
    it('allows a burst of 4 api calls, then says when the next slot frees up', function () {
        foreach (range(1, 4) as $i) {
            expect($this->rc->acquire(api(), Priority::Interactive, 0))->toBeInstanceOf(Slot::class);
        }

        $busy = $this->rc->acquire(api(), Priority::Interactive, 0);

        expect($busy)->toBeInstanceOf(Busy::class)
            ->and($busy->reason)->toBe(BusyReason::Saturated)
            ->and($busy->retryAt)->toEqualWithDelta(T0 + 0.25, 0.001);
    });

    it('refills at 4 tokens per second', function () {
        foreach (range(1, 4) as $i) {
            $this->rc->acquire(api(), Priority::Interactive, 0);
        }

        advance(0.25);

        expect($this->rc->acquire(api(), Priority::Interactive, 0))->toBeInstanceOf(Slot::class);
    });

    it('waits for a slot when maxWait allows it', function () {
        foreach (range(1, 4) as $i) {
            $this->rc->acquire(api(), Priority::Interactive, 0);
        }

        expect($this->rc->acquire(api(), Priority::Interactive, 1.0))->toBeInstanceOf(Slot::class)
            ->and(clock())->toEqualWithDelta(T0 + 0.25, 0.002);
    });

    it('takes from the global and the endpoint bucket', function () {
        $slot = $this->rc->acquire(api('/at-home/server/abc'), Priority::Interactive, 0);

        expect($slot->buckets)->toBe(['global', 'at-home'])
            ->and(tokens('global'))->toBe(3.0)
            ->and(tokens('at-home'))->toBe(37.0);
    });

    it('is saturated when the endpoint bucket is empty even if global has room', function () {
        $this->rc->acquire(api('/at-home/server/abc'), Priority::Interactive, 0);
        DB::table('rate_buckets')->where('key', 'at-home')->update(['tokens' => 0]);

        $busy = $this->rc->acquire(api('/at-home/server/def'), Priority::Interactive, 0);

        expect($busy->reason)->toBe(BusyReason::Saturated)
            ->and($busy->retryAt)->toEqualWithDelta(T0 + 60 / 38, 0.001);
    });
});

describe('priorities', function () {
    it('caps prefetch and bulk at 3 of the 4 global tokens so the screen keeps room', function () {
        expect($this->rc->acquire(api(), Priority::Bulk, 0))->toBeInstanceOf(Slot::class)
            ->and($this->rc->acquire(api(), Priority::Prefetch, 0))->toBeInstanceOf(Slot::class)
            ->and($this->rc->acquire(api(), Priority::Bulk, 0))->toBeInstanceOf(Slot::class)
            ->and($this->rc->acquire(api(), Priority::Bulk, 0))->toBeInstanceOf(Busy::class)
            ->and($this->rc->acquire(api(), Priority::Interactive, 0))->toBeInstanceOf(Slot::class);
    });

    it('caps background at-home lookups at 30 per minute', function () {
        $this->rc->acquire(api('/at-home/server/a'), Priority::Bulk, 0);
        DB::table('rate_buckets')->where('key', 'at-home:background')->update(['tokens' => 0]);

        expect($this->rc->acquire(api('/at-home/server/b'), Priority::Bulk, 0))->toBeInstanceOf(Busy::class)
            ->and($this->rc->acquire(api('/at-home/server/c'), Priority::Interactive, 0))->toBeInstanceOf(Slot::class);
    });
});

describe('rate-limit headers', function () {
    it('never keeps more tokens than MangaDex says remain, minus the margin', function () {
        $slot = $this->rc->acquire(api('/at-home/server/a'), Priority::Interactive, 0);

        $this->rc->record($slot, reply(200, ['X-RateLimit-Limit' => '40', 'X-RateLimit-Remaining' => '10', 'X-RateLimit-Retry-After' => (string) (T0 + 40)]));

        expect(tokens('at-home'))->toBe(8.0);
    });

    it('blocks the endpoint until the reset time when 2 or fewer remain', function () {
        $slot = $this->rc->acquire(api('/at-home/server/a'), Priority::Interactive, 0);
        $this->rc->record($slot, reply(200, ['X-RateLimit-Limit' => '40', 'X-RateLimit-Remaining' => '2', 'X-RateLimit-Retry-After' => (string) (T0 + 30)]));

        $busy = $this->rc->acquire(api('/at-home/server/b'), Priority::Interactive, 0);

        expect($busy->retryAt)->toBe(T0 + 30)
            ->and($this->rc->acquire(api('/manga'), Priority::Interactive, 0))->toBeInstanceOf(Slot::class);
    });

    it('follows a learned limit instead of the config', function () {
        $slot = $this->rc->acquire(api('/at-home/server/a'), Priority::Interactive, 0);

        $this->rc->record($slot, reply(200, ['X-RateLimit-Limit' => '20', 'X-RateLimit-Remaining' => '19']));

        $row = DB::table('rate_buckets')->where('key', 'at-home')->first();
        expect((int) $row->learned_limit)->toBe(20)
            ->and((float) $row->capacity)->toBe(19.0)
            ->and((float) $row->tokens)->toBe(18.0);
    });

    it('ignores calls without headers', function () {
        $slot = $this->rc->acquire(api('/at-home/server/a'), Priority::Interactive, 0);

        $this->rc->record($slot, reply(200));

        expect(tokens('at-home'))->toBe(37.0);
    });
});
