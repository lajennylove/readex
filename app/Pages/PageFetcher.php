<?php

namespace App\Pages;

use App\MangaDex\Client;
use App\MangaDex\Exceptions\ApiError;
use App\MangaDex\Exceptions\RequestDeferred;
use App\MangaDex\RateLimit\Priority;
use App\Models\AtHome;
use App\Models\Chapter;
use App\Models\StoredChapter;
use App\Repositories\AtHomeRepository;
use App\Repositories\SettingsRepository;
use App\Storage\PageStore;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Downloads a chapter's pages into the PageStore (spec §5.6, §12, §13). The reader says which
 * pages it shows (`want()`); a FetchPages task on the async lane follows that list, a
 * DownloadChapter job takes the rest in reading order. Pages already on disk never touch the
 * network, so a stored chapter reads in Airplane mode.
 *
 * An image that fails gets one fresh @Home lookup per run and one retry; after that the page is
 * marked failed ("Tap to retry"), the rest keep loading. A connection error is never a failed
 * page: the run stops as offline and the page waits. A lookup MangaDex answers with 5xx, 429 or
 * 403 is never "removed" either: the run stops and the chapter is retried.
 */
final class PageFetcher
{
    /** The reader's wanted pages are kept this long (it re-sends them on every window change). */
    private const WANT_SECONDS = 600;

    public function __construct(
        private readonly AtHomeRepository $atHome,
        private readonly PageStore $pages,
        private readonly SettingsRepository $settings,
        private readonly Client $client,
    ) {}

    /**
     * The pages the reader shows now (0-based, reading order). Starts a FetchPages task when one
     * of them is missing and none runs for this chapter; a running task picks the new list up.
     */
    public function want(string $chapterId, array $indexes): bool
    {
        $indexes = array_values(array_unique(array_filter($indexes, fn ($i) => is_int($i) && $i >= 0)));
        Cache::put($this->key('want', $chapterId), $indexes, self::WANT_SECONDS);

        if ($this->nextWanted($chapterId, []) === null) {
            return false;
        }

        if (! Cache::add($this->key('running', $chapterId), true, config('readex.pages.running_seconds'))) {
            return false;
        }

        FetchPages::dispatch($chapterId);

        return true;
    }

    /** Tap to retry: the page is wanted again with a fresh retry budget. */
    public function retry(string $chapterId, int $index): bool
    {
        $this->forgetFailed($chapterId, $index);
        $wanted = Cache::get($this->key('want', $chapterId), []);

        return $this->want($chapterId, [$index, ...$wanted]);
    }

    /** @return list<int> pages that failed twice, shown with "Tap to retry" */
    public function failed(string $chapterId): array
    {
        return Cache::get($this->key('failed', $chapterId), []);
    }

    public function state(string $chapterId): ?LoadState
    {
        return LoadState::tryFrom((string) Cache::get($this->key('state', $chapterId)));
    }

    public function isRunning(string $chapterId): bool
    {
        return Cache::has($this->key('running', $chapterId));
    }

    /** Called by FetchPages when it stops: the next want() may start a task again. */
    public function finished(string $chapterId, LoadState $state): void
    {
        Cache::forget($this->key('running', $chapterId));
        $this->record($chapterId, $state);
    }

    public function record(string $chapterId, LoadState $state): void
    {
        Cache::put($this->key('state', $chapterId), $state->value, config('readex.pages.failed_seconds'));
    }

    /**
     * Next wanted page that is missing, not failed and not in `$skip`; null when there is none.
     * Before the chapter's first lookup every wanted page counts as missing.
     */
    public function nextWanted(string $chapterId, array $skip): ?int
    {
        $wanted = Cache::get($this->key('want', $chapterId), []);
        $pages = $this->pages->manifest($chapterId) === null ? null : $this->pages->pages($chapterId);
        $failed = $this->failed($chapterId);

        foreach ($wanted as $index) {
            if ($pages !== null && (! array_key_exists($index, $pages) || $pages[$index] !== null)) {
                continue;
            }

            if (! in_array($index, $skip, true) && ! in_array($index, $failed, true)) {
                return $index;
            }
        }

        return null;
    }

    /** Next missing page in reading order that is not failed and not in `$skip`. */
    public function nextMissing(string $chapterId, array $skip): ?int
    {
        if ($this->pages->manifest($chapterId) === null) {
            return in_array(0, $skip, true) ? null : 0;
        }

        $failed = $this->failed($chapterId);

        foreach ($this->pages->missing($chapterId) as $index) {
            if (! in_array($index, $skip, true) && ! in_array($index, $failed, true)) {
                return $index;
            }
        }

        return null;
    }

    /** Pages still to download: missing and not failed. */
    public function remaining(string $chapterId): int
    {
        if ($this->pages->manifest($chapterId) === null) {
            return PHP_INT_MAX;
        }

        return count(array_diff($this->pages->missing($chapterId), $this->failed($chapterId)));
    }

    /**
     * Download pages one by one in the order `$next(array $skip): ?int` gives them, until it
     * returns null or `$until` has passed. Looks the @Home server up only when a page is
     * actually needed. Returns the number of pages stored.
     *
     * @throws RequestDeferred no request slot
     * @throws ConnectionException offline
     * @throws ApiError the @Home lookup failed (chapter removed)
     * @throws MangaDexTrouble the @Home lookup got 5xx / 429 / 403
     * @throws ChapterUnavailable the lookup lists no pages
     */
    public function run(Chapter $chapter, Closure $next, Priority $priority, float $maxWait, string $tier = 'cache', ?CarbonInterface $until = null): int
    {
        $skip = [];
        $stored = 0;
        $session = null;

        while (($index = $next($skip)) !== null) {
            if ($until !== null && Carbon::now()->greaterThanOrEqualTo($until)) {
                break;
            }

            $session ??= $this->open($chapter, $priority, $tier);

            if ($index >= count($session['urls'])) {
                $skip[] = $index;

                continue;
            }

            if (! Cache::add($this->claimKey($chapter->id, $index), true, config('readex.pages.claim_seconds'))) {
                $skip[] = $index;

                continue;
            }

            try {
                $stored += $this->fetchPage($chapter->id, $index, $session, $priority, $maxWait) ? 1 : 0;
            } finally {
                Cache::forget($this->claimKey($chapter->id, $index));
            }
        }

        return $stored;
    }

    /**
     * @param  array{server: AtHome, quality: string, urls: list<string>, relooked: bool}  $session
     *
     * @throws RequestDeferred|ConnectionException|ApiError|MangaDexTrouble
     */
    private function fetchPage(string $chapterId, int $index, array &$session, Priority $priority, float $maxWait): bool
    {
        try {
            if ($this->download($chapterId, $index, $session['urls'][$index], $priority, $maxWait)) {
                return true;
            }
        } catch (ApiError) {
        } catch (ConnectionException $e) {
            if ($session['relooked']) {
                throw $e;
            }
        }

        if (! $session['relooked']) {
            $session['relooked'] = true;
            $this->atHome->forget($chapterId);
            $session['server'] = $this->server($chapterId, $priority);
            $session['urls'] = AtHomeRepository::pageUrls($session['server'], $session['quality']);

            try {
                if (isset($session['urls'][$index]) && $this->download($chapterId, $index, $session['urls'][$index], $priority, $maxWait)) {
                    return true;
                }
            } catch (ApiError) {
            }
        }

        $this->markFailed($chapterId, $index);

        return false;
    }

    /** @throws RequestDeferred|ConnectionException|ApiError */
    private function download(string $chapterId, int $index, string $url, Priority $priority, float $maxWait): bool
    {
        return $this->pages->put($chapterId, $index, $this->client->download($url, $priority, $maxWait));
    }

    /**
     * Server + URLs in the chapter's quality: the quality it started with, else the setting (§12.1).
     *
     * @return array{server: AtHome, quality: string, urls: list<string>, relooked: bool}
     *
     * @throws RequestDeferred|ConnectionException|ApiError|MangaDexTrouble|ChapterUnavailable
     */
    private function open(Chapter $chapter, Priority $priority, string $tier): array
    {
        $quality = StoredChapter::query()->find($chapter->id)?->quality ?? $this->settings->imageQuality();
        $server = $this->server($chapter->id, $priority);
        $urls = AtHomeRepository::pageUrls($server, $quality);

        if ($urls === []) {
            throw new ChapterUnavailable("No @Home pages for chapter [{$chapter->id}].");
        }

        $this->pages->begin($chapter, $quality, count($urls), $tier);

        return ['server' => $server, 'quality' => $quality, 'urls' => $urls, 'relooked' => false];
    }

    /**
     * The @Home server; 5xx, 429 and 403 are MangaDex's trouble, any other error means the chapter is gone.
     *
     * @throws RequestDeferred|ConnectionException|ApiError|MangaDexTrouble
     */
    private function server(string $chapterId, Priority $priority): AtHome
    {
        try {
            return $this->atHome->server($chapterId, $priority);
        } catch (ApiError $e) {
            if ($e->status >= 500 || in_array($e->status, [403, 429], true)) {
                throw new MangaDexTrouble($e->getMessage(), $e->status, $e);
            }

            throw $e;
        }
    }

    private function markFailed(string $chapterId, int $index): void
    {
        $failed = $this->failed($chapterId);

        if (! in_array($index, $failed, true)) {
            Cache::put($this->key('failed', $chapterId), [...$failed, $index], config('readex.pages.failed_seconds'));
        }
    }

    private function forgetFailed(string $chapterId, int $index): void
    {
        $failed = $this->failed($chapterId);

        if (in_array($index, $failed, true)) {
            Cache::put($this->key('failed', $chapterId), array_values(array_diff($failed, [$index])), config('readex.pages.failed_seconds'));
        }
    }

    private function key(string $kind, string $chapterId): string
    {
        return "readex.pages.{$kind}.{$chapterId}";
    }

    private function claimKey(string $chapterId, int $index): string
    {
        return "readex.pages.claim.{$chapterId}.{$index}";
    }
}
