<?php

namespace App\Pages;

use App\Jobs\DownloadChapter;
use App\MangaDex\Exceptions\ApiError;
use App\MangaDex\Exceptions\RequestDeferred;
use App\MangaDex\RateLimit\Priority;
use App\Models\Chapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Native\Mobile\AsyncTask;
use Throwable;

/**
 * The pages the reader shows, downloaded at once on the async lane (the phone's queue worker
 * pauses after every job and stops in the background). Follows the reader's wanted list while
 * it runs, so a fast scroll moves it to the new window. One per chapter (PageFetcher::want()).
 * When it stops, the rest of the chapter goes to a DownloadChapter job. Never throws.
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
        } catch (ApiError|ChapterUnavailable) {
            $state = LoadState::Unavailable;
        } catch (Throwable $e) {
            report($e);
            $state = LoadState::Failed;
        }

        $fetcher->finished($chapterId, $state);

        $queued = in_array($state, [LoadState::Done, LoadState::Busy, LoadState::Offline], true)
            && $fetcher->remaining($chapterId) > 0;

        if ($queued) {
            DownloadChapter::dispatch($chapterId, 'cache', Priority::Interactive);
        }

        return ['state' => $state->value, 'fetched' => $fetched, 'queued' => $queued];
    }
}
