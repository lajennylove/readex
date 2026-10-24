<?php

namespace App\Jobs;

use App\MangaDex\Exceptions\ApiError;
use App\MangaDex\Exceptions\RequestDeferred;
use App\MangaDex\RateLimit\Priority;
use App\Models\Chapter;
use App\Pages\ChapterUnavailable;
use App\Pages\LoadState;
use App\Pages\PageFetcher;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;

/**
 * Spec §5.6: the missing pages of a chapter in reading order, in the quality it started with.
 * Resumes from what's on disk. Works in slices of job_budget_seconds: pages left → released at
 * once to continue, behind the other queued jobs. No slot → release until the controller's retry
 * time; offline → again in a minute; pages a FetchPages task is downloading are left to it. A
 * cache run ends by queueing EnforceCacheCap.
 */
class DownloadChapter implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const GIVE_UP_AFTER_SECONDS = 3600;

    public const OFFLINE_RETRY_SECONDS = 60;

    /** Seconds to wait for a request slot before releasing (spec §5.1a). */
    public const MAX_WAIT_SECONDS = 2.0;

    public int $uniqueFor = self::GIVE_UP_AFTER_SECONDS;

    /** Under the database queue's retry_after (90 s), so a running slice is never handed out twice. */
    public int $timeout = 80;

    public function __construct(
        public readonly string $chapterId,
        public readonly string $tier = 'cache',
        public readonly Priority $priority = Priority::Prefetch,
    ) {}

    public function uniqueId(): string
    {
        return $this->chapterId;
    }

    public function retryUntil(): CarbonInterface
    {
        return Carbon::now()->addSeconds(self::GIVE_UP_AFTER_SECONDS);
    }

    public function handle(PageFetcher $fetcher): void
    {
        $chapter = Chapter::query()->find($this->chapterId);

        if ($chapter === null || $chapter->external_url !== null) {
            return;
        }

        try {
            $fetcher->run(
                $chapter,
                fn (array $skip) => $fetcher->nextMissing($this->chapterId, $skip),
                $this->priority,
                self::MAX_WAIT_SECONDS,
                $this->tier,
                until: Carbon::now()->addSeconds(config('readex.pages.job_budget_seconds')),
            );
        } catch (RequestDeferred $e) {
            $fetcher->record($this->chapterId, LoadState::Busy);
            $this->release($this->secondsUntil($e->busy->retryAt));

            return;
        } catch (ConnectionException) {
            $fetcher->record($this->chapterId, LoadState::Offline);
            $this->release(self::OFFLINE_RETRY_SECONDS);

            return;
        } catch (ApiError|ChapterUnavailable) {
            $fetcher->record($this->chapterId, LoadState::Unavailable);

            return;
        } finally {
            if ($this->tier === 'cache') {
                EnforceCacheCap::dispatch();
            }
        }

        if ($fetcher->remaining($this->chapterId) > 0) {
            $fetcher->record($this->chapterId, LoadState::Busy);
            $this->release(0);

            return;
        }

        $fetcher->record($this->chapterId, LoadState::Done);
    }

    private function secondsUntil(float $retryAt): int
    {
        return max(1, (int) ceil($retryAt - Carbon::now()->getTimestampMs() / 1000));
    }
}
