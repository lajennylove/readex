<?php

namespace App\MangaDex;

use App\MangaDex\Exceptions\ApiError;
use App\MangaDex\Exceptions\RequestDeferred;
use App\MangaDex\RateLimit\Busy;
use App\MangaDex\RateLimit\Call;
use App\MangaDex\RateLimit\Priority;
use App\MangaDex\RateLimit\RequestController;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The only way the app talks to MangaDex, its @Home image servers, uploads.mangadex.org
 * and the seasonal JSON. Every request asks the RequestController for a slot first and
 * hands it the response afterwards; this class holds no rate-limit logic of its own.
 */
final class Client
{
    public function __construct(private readonly RequestController $controller) {}

    /**
     * GET an api.mangadex.org path. JSON is decoded to associative arrays, so MangaDex's
     * empty objects (`{}`, sometimes sent as `[]`) both arrive as `[]`.
     *
     * @throws RequestDeferred|ApiError|ConnectionException
     */
    public function get(string $path, array $query = [], Priority $priority = Priority::Interactive, float $maxWait = 10.0): array
    {
        $url = rtrim(config('mangadex.api_url'), '/').'/'.ltrim($path, '/');

        return $this->getJson($query === [] ? $url : $url.'?'.Query::build($query), $priority, $maxWait);
    }

    /** @throws RequestDeferred|ApiError|ConnectionException */
    public function getJson(string $url, Priority $priority = Priority::Interactive, float $maxWait = 10.0): array
    {
        $body = $this->shared($url, $maxWait, fn () => $this->send(Call::get($url), $priority, $maxWait)->body());

        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : [];
    }

    /**
     * Download raw bytes (page images, covers). Results from non-mangadex.org hosts
     * are reported to the @Home network.
     *
     * @throws RequestDeferred|ApiError|ConnectionException
     */
    public function download(string $url, Priority $priority = Priority::Interactive, float $maxWait = 10.0): string
    {
        $call = Call::get($url);
        $report = ! $this->isMangaDexHost($call->host());
        $started = hrtime(true);

        try {
            $response = $this->send($call, $priority, $maxWait);
        } catch (ApiError|ConnectionException $e) {
            if ($report) {
                $this->report($url, false, 0, $started, false);
            }

            throw $e;
        }

        $body = $response->body();

        if ($report) {
            $this->report($url, true, strlen($body), $started, str_starts_with((string) $response->header('X-Cache'), 'HIT'));
        }

        return $body;
    }

    public function userAgent(): string
    {
        $agent = 'ReaDex/'.config('mangadex.app_version');
        $contact = config('mangadex.contact');

        return $contact ? "{$agent} (+{$contact})" : $agent;
    }

    private function send(Call $call, Priority $priority, float $maxWait): Response
    {
        $slot = $this->controller->acquire($call, $priority, $maxWait);

        if ($slot instanceof Busy) {
            throw new RequestDeferred($slot);
        }

        try {
            $response = $this->http()->send($call->method, $call->url);
        } catch (ConnectionException $e) {
            $this->controller->record($slot, null);

            throw $e;
        }

        $this->controller->record($slot, $response);

        if ($response->failed()) {
            throw ApiError::fromResponse($response);
        }

        return $response;
    }

    /** Identical GETs in flight or finished within share_seconds get one response. */
    private function shared(string $url, float $maxWait, Closure $fetch): string
    {
        $store = Cache::store(config('mangadex.share_store'));
        $key = 'mangadex:get:'.sha1($url);

        if (is_string($body = $store->get($key))) {
            return $body;
        }

        $lock = $store->lock($key.':lock', (int) ceil($maxWait) + 30);

        try {
            $lock->block($maxWait);
        } catch (LockTimeoutException) {
            return $fetch();
        }

        try {
            if (is_string($body = $store->get($key))) {
                return $body;
            }

            $body = $fetch();
            $store->put($key, $body, config('mangadex.share_seconds'));

            return $body;
        } finally {
            $lock->release();
        }
    }

    /** Fire and forget: dropped when the report bucket is busy, never throws. */
    private function report(string $url, bool $success, int $bytes, int $startedNs, bool $cached): void
    {
        $call = new Call('POST', config('mangadex.report_url'));
        $slot = $this->controller->acquire($call, Priority::Bulk, 0);

        if ($slot instanceof Busy) {
            return;
        }

        try {
            $response = $this->http()->asJson()->post($call->url, [
                'url' => $url,
                'success' => $success,
                'cached' => $cached,
                'bytes' => $bytes,
                'duration' => intdiv(hrtime(true) - $startedNs, 1_000_000),
            ]);
        } catch (Throwable) {
            $response = null;
        }

        $this->controller->record($slot, $response);
    }

    private function http(): PendingRequest
    {
        return Http::withUserAgent($this->userAgent())
            ->connectTimeout(config('mangadex.connect_timeout'))
            ->timeout(config('mangadex.timeout'));
    }

    private function isMangaDexHost(string $host): bool
    {
        return $host === 'mangadex.org' || str_ends_with($host, '.mangadex.org');
    }
}
