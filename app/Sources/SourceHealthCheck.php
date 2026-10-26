<?php

declare(strict_types=1);

namespace App\Sources;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * First-run (and Settings → Edit source) URL validator.
 *
 * ReaDex is agnostic — the user picks the source. Before we store a URL, we
 * confirm it actually serves the ReaDex endpoint convention. The probe is a
 * GET of `{url}/api/manga/_catalog.json` with a short timeout. We accept
 * any 2xx response whose JSON body carries the expected envelope
 * (`result: ok` + `response: collection` + `data: list`). Any other shape
 * means the URL does respond but isn't a ReaDex source; a timeout / 4xx /
 * 5xx means the URL doesn't respond at all.
 */
final class SourceHealthCheck
{
    public const TIMEOUT_SECONDS = 8;

    public function check(string $baseUrl): HealthCheckResult
    {
        $baseUrl = rtrim(trim($baseUrl), '/');

        if ($baseUrl === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            return HealthCheckResult::badUrl();
        }

        $endpoint = $baseUrl.'/api/manga/_catalog.json';

        try {
            $response = Http::connectTimeout(self::TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->get($endpoint);
        } catch (ConnectionException) {
            return HealthCheckResult::unreachable();
        }

        if (! $response->successful()) {
            return HealthCheckResult::unreachable();
        }

        $body = $response->json();

        if (! is_array($body)
            || ($body['result'] ?? null) !== 'ok'
            || ($body['response'] ?? null) !== 'collection'
            || ! isset($body['data']) || ! is_array($body['data'])
        ) {
            return HealthCheckResult::wrongShape();
        }

        return HealthCheckResult::ok();
    }
}

final class HealthCheckResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $error,
    ) {}

    public static function ok(): self
    {
        return new self(true, null);
    }

    public static function badUrl(): self
    {
        return new self(false, 'That URL does not look right. Check for typos and try again.');
    }

    public static function unreachable(): self
    {
        return new self(false, 'That source did not respond. Check the URL and your connection and try again.');
    }

    public static function wrongShape(): self
    {
        return new self(false, 'That URL responded but does not look like a ReaDex source. Check the URL and try again.');
    }
}
