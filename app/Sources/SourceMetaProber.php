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

        // The source either publishes a /_meta.json with `{static_json: bool}` (our demo does)
        // or it doesn't (a dynamic REST API like a public manga API won't). First is the
        // demo shape, second is dynamic REST — static_json defaults to false if we can't tell.
        try {
            $response = Http::connectTimeout(self::TIMEOUT_SECONDS)->timeout(self::TIMEOUT_SECONDS)
                ->get($baseUrl.'/_meta.json');
        } catch (\Throwable) {
            $this->settings->setSourceIsStaticJson(false);

            return;
        }

        if (! $response->successful()) {
            $this->settings->setSourceIsStaticJson(false);

            return;
        }

        $body = $response->json();
        $this->settings->setSourceIsStaticJson(is_array($body) && (bool) ($body['static_json'] ?? false));
    }
}
