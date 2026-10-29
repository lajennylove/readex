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

    /**
     * The API base, used verbatim as the user typed it at Setup. ReaDex does not append any
     * hidden path segment: the demo prefills `.../readex/api`, a dynamic REST source would be
     * typed with the exact path the API serves at, and each repository prefixes its own
     * endpoint path onto this base.
     */
    public function apiUrl(): string
    {
        return $this->baseUrl();
    }

    public function coversUrl(): string
    {
        $explicit = $this->settings->coversUrl();
        if ($explicit !== null) {
            return $explicit;
        }

        // Many sources — the demo included — serve the API at `.../api` and the covers at
        // `.../covers` (siblings, not a child of the API). Fall back to that layout by
        // trimming a trailing `/api` off the base URL before appending `/covers`.
        return preg_replace('~/api$~', '', $this->baseUrl()).'/covers';
    }

    public function reportUrl(): string
    {
        // The @Home report endpoint is a sibling of the API (same reason as coversUrl —
        // the demo lays /api, /covers and /report out side by side under one base).
        return preg_replace('~/api$~', '', $this->baseUrl()).'/report';
    }

    /**
     * Optional curated-list URL. null when the user hasn't configured one;
     * the "This season" row on Home then stays hidden.
     */
    public function seasonalUrl(): ?string
    {
        return $this->settings->seasonalUrl();
    }

    public function isConfigured(): bool
    {
        $url = $this->settings->sourceUrl();

        return $url !== null && $url !== '';
    }
}
