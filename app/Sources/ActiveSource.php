<?php

declare(strict_types=1);

namespace App\Sources;

use App\Repositories\SettingsRepository;

/**
 * The one content source URL the user configured at first-run Setup.
 *
 * ReaDex is agnostic: the app has NO hardcoded source. Everything (catalog
 * lookups, chapter feeds, at-home requests, cover thumbnails, @Home reports)
 * routes through the single URL the user supplied. The URL is stored in
 * SettingsRepository under the `source_url` key; Setup gatekeeps so Home
 * never opens without one.
 *
 * Endpoint convention (what the user's URL must serve to be a valid ReaDex source):
 *   `{base}/api/manga/_catalog.json`         — catalog listing
 *   `{base}/api/manga/{id}.json`             — title detail
 *   `{base}/api/manga/{id}/feed.json`        — chapter feed
 *   `{base}/api/at-home/server/{chapter}.json`
 *                                            — page list (empty `baseUrl` in
 *                                              response is treated as same-origin
 *                                              as api_url; see AtHomeRepository)
 *   `{base}/covers/{manga}/cover.*`          — cover thumbnails
 *   `{base}/report`                          — @Home report sink
 *
 * The demo content source at https://lajennylove.github.io/readex is the
 * canonical shape; any source the user points ReaDex at must match it.
 */
final class ActiveSource
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function baseUrl(): string
    {
        $url = $this->settings->sourceUrl();

        if ($url === null || $url === '') {
            throw new NoSourceConfigured;
        }

        return rtrim($url, '/');
    }

    public function apiUrl(): string
    {
        return $this->baseUrl().'/api';
    }

    public function coversUrl(): string
    {
        return $this->baseUrl().'/covers';
    }

    public function reportUrl(): string
    {
        return $this->baseUrl().'/report';
    }

    public function isConfigured(): bool
    {
        $url = $this->settings->sourceUrl();

        return $url !== null && $url !== '';
    }
}
