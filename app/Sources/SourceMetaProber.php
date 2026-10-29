<?php

declare(strict_types=1);

namespace App\Sources;

use App\Repositories\SettingsRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Reads `{sourceUrl}/_meta.json` right after a source URL changes and writes
 * `source_is_static_json` to Settings so the HTTP Client knows whether to append `.json` to
 * each path. The demo publishes a /_meta.json with `static_json: true`; a dynamic REST source
 * publishes nothing at that path, which the Client reads as "dynamic" (static_json false).
 *
 * No throws: any probe failure defaults to "dynamic" rather than blocking the source swap.
 */
final class SourceMetaProber
{
    private const TIMEOUT_SECONDS = 5;

    public function __construct(private readonly SettingsRepository $settings) {}

    public function probe(string $baseUrl): void
    {
        $baseUrl = rtrim(trim($baseUrl), '/');

        if ($baseUrl === '') {
            return;
        }

        // Try /_meta.json first (user typed the full API URL including any /api); fall back to
        // /api/_meta.json (user typed just the site root — the demo publishes its meta one
        // level deeper). The first one that answers 200 with a valid envelope wins and tells
        // us which api suffix the Client should use from then on.
        foreach (['', '/api'] as $suffix) {
            try {
                $response = Http::connectTimeout(self::TIMEOUT_SECONDS)->timeout(self::TIMEOUT_SECONDS)
                    ->get($baseUrl.$suffix.'/_meta.json');
            } catch (\Throwable) {
                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            $body = $response->json();
            if (! is_array($body)) {
                continue;
            }

            $this->settings->setSourceApiSuffix($suffix);
            $this->settings->setSourceIsStaticJson((bool) ($body['static_json'] ?? false));

            return;
        }

        // Neither probe answered — leave both settings at their defaults so the test suite's
        // fixtures (and anything else that doesn't publish /_meta.json) keep working. A real
        // dynamic REST source that needs a different suffix is overridden through Settings.
    }
}
