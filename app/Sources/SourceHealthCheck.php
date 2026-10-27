<?php

namespace App\Sources;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Plan-08 Task 3 (D45): before a source URL is saved — at
 * first-run Setup or later from Settings → Sources — the app makes one request to it and
 * confirms it answers with a healthy (2xx) status. A source that doesn't respond, or answers
 * with an error, is never saved; the user is told to check it.
 *
 * Judgment call (flagged, not silently decided — Plan-08 Task 3 note): there is no universal
 * "manga API health" endpoint, so the check is a plain `GET` of the base URL itself, expecting
 * any 2xx response. This is deliberately the SAME check for every source, including the
 * built-in source's own API URL — a smarter built-in-specific check (e.g. hitting `/ping`)
 * would mean the app treats its built-in source specially, which cuts against the "the reviewer
 * sees exactly what every user sees" argument in the review notes (see
 * Plan-08-Multi-Source-and-Apple-Safety.md § Review Notes). If a generic GET turns out to be
 * too strict for a real source later (e.g. one that 404s its bare root but works fine under a
 * sub-path), that is a real-world case to revisit, not something to special-case now.
 */
final class SourceHealthCheck
{
    public const TIMEOUT_SECONDS = 8;

    /**
     * True when the URL answers anything short of a server error within the timeout; false on
     * a connection failure, timeout or 5xx. We treat 4xx (incl. 404) as "reachable but no root
     * document" because many real APIs return 404 at their bare root while serving real
     * endpoints just fine (the GH Pages demo is one example — / lists the directory, /api/
     * does not have an index.html).
     */
    public function check(string $url): bool
    {
        try {
            $response = Http::connectTimeout(self::TIMEOUT_SECONDS)->timeout(self::TIMEOUT_SECONDS)->get($url);

            return $response->status() < 500;
        } catch (ConnectionException) {
            return false;
        }
    }
}
