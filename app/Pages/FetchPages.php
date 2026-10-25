<?php

namespace App\Pages;

use App\Adapters\Http\Exceptions\ApiError;
use App\Adapters\Http\Exceptions\RequestDeferred;
use App\Adapters\Http\RateLimit\Priority;
use App\Jobs\DownloadChapter;
use App\Jobs\EnforceCacheCap;
use App\Models\Chapter;
use App\Storage\ChapterGone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Native\Mobile\AsyncTask;
use Throwable;

/**
 * The pages the reader shows, downloaded at once on the async lane (the phone's queue worker
 * pauses after every job and stops in the background). Follows the reader's wanted list while
 * it runs, so a fast scroll moves it to the new window. One per chapter (PageFetcher::want()).
 * When it stops, the rest of the chapter goes to a DownloadChapter job (not for a chapter the
 * image cache dropped meanwhile), and pages stored queue EnforceCacheCap. Never throws.
 */
class FetchPages extends AsyncTask
{
    /** Seconds to wait for a request slot before handing the rest to the queue. */
    public const MAX_WAIT_SECONDS = 2.0;

    /** @return array{state: string, fetched: int, queued: bool} */
    public function handle(string $chapterId): array
    {
        $fetcher = app(PageFetcher::class);
        $fetched = 0;
        $gone = false;

        try {
            $chapter = Chapter::query()->find($chapterId);

            if ($chapter === null || $chapter->external_url !== null) {
                throw new ChapterUnavailable("Chapter [{$chapterId}] has no @Home pages.");
            }

            $fetched = $fetcher->run(
                $chapter,
                fn (array $skip) => $fetcher->nextWanted($chapterId, $skip),
                Priority::Interactive,
                self::MAX_WAIT_SECONDS,
                until: Carbon::now()->addSeconds(config('readex.pages.task_budget_seconds')),
            );
            $state = LoadState::Done;
        } catch (RequestDeferred) {
            $state = LoadState::Busy;
        } catch (ConnectionException) {
            $state = LoadState::Offline;
        } catch (ChapterGone) {
            // The image cache dropped a chapter no longer open: nothing left to fetch for it.
            $state = LoadState::Done;
            $gone = true;
        } catch (MangaDexTrouble) {
            $state = LoadState::Retrying;
        } catch (ApiError|ChapterUnavailable) {
            $state = LoadState::Unavailable;
        } catch (Throwable $e) {
            report($e);
            $state = LoadState::Failed;
        }

        $fetcher->finished($chapterId, $state);

        if ($fetched > 0) {
            EnforceCacheCap::dispatch();
        }

        $queued = ! $gone && in_array($state, [LoadState::Done, LoadState::Busy, LoadState::Offline, LoadState::Retrying], true)
            && $fetcher->remaining($chapterId) > 0;

        if ($queued) {
            DownloadChapter::dispatch($chapterId, 'cache', Priority::Interactive);
        }

        return ['state' => $state->value, 'fetched' => $fetched, 'queued' => $queued];
    }
}
