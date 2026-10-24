<?php

namespace App\Jobs;

use App\Downloads\Downloads;
use App\MangaDex\Exceptions\ApiError;
use App\MangaDex\Exceptions\RequestDeferred;
use App\MangaDex\RateLimit\Priority;
use App\Models\Chapter;
use App\Pages\ChapterUnavailable;
use App\Pages\LoadState;
use App\Pages\MangaDexTrouble;
use App\Pages\PageFetcher;
use App\Storage\ChapterGone;
use App\Storage\PageStore;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Spec §5.6: the missing pages of a chapter in reading order, in the quality it started with.
 * Resumes from what's on disk. Works in time-boxed slices of job_budget_seconds: pages left →
 * released at once to continue, behind the other queued jobs. Pages another fetcher is
 * downloading are left to it; when only those are left, the job checks back in 5 s. No slot →
 * release until the controller's retry time; offline → again in a minute; MangaDex answering the
 * lookup with 5xx / 429 / 403 → again after 1 s, 4 s, 15 s, then every minute. A cache run ends
 * by queueing EnforceCacheCap. A cache chapter that Clear image cache or the cap drops ends the
 * job quietly, also between slices: only the reader or a download begins it again.
 *
 * Download work (spec §11.3): a `local` job, or any job for a chapter in the download queue
 * (the unique lock drops a download's dispatch while the reader's own job runs). It writes into
 * the local tier, checks the queue between pages so Stop ends it at the next page, and tells
 * Downloads when the chapter ends (done, unavailable, failed for good) so a Download all moves on.
 */
class DownloadChapter implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const GIVE_UP_AFTER_SECONDS = 3600;

    public const OFFLINE_RETRY_SECONDS = 60;

    /** Seconds before checking back when the only pages left are being downloaded by another fetcher. */
    public const CLAIMED_RETRY_SECONDS = 5;

    /** Spec §13 backoff while MangaDex has trouble with the lookup; the last one repeats. */
    public const TROUBLE_RETRY_SECONDS = [1, 4, 15, 60];

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
        $downloads = app(Downloads::class);
        $chapter = Chapter::query()->find($this->chapterId);

        if ($chapter === null || $chapter->external_url !== null) {
            $downloads->ended($this->chapterId);

            return;
        }

        $download = $this->tier === 'local' || $downloads->isQueued($this->chapterId);
        $stopped = fn () => $download && ! $downloads->isQueued($this->chapterId);

        if ($stopped() || (! $download && app(PageStore::class)->wasEvicted($this->chapterId))) {
            return;
        }

        if ($download) {
            app(PageStore::class)->promote($this->chapterId);
        }

        $until = Carbon::now()->addSeconds(config('readex.pages.job_budget_seconds'));

        try {
            $fetcher->run(
                $chapter,
                fn (array $skip) => $stopped() ? null : $fetcher->nextMissing($this->chapterId, $skip),
                $this->priority,
                self::MAX_WAIT_SECONDS,
                $download ? 'local' : $this->tier,
                $until,
            );
        } catch (RequestDeferred $e) {
            $fetcher->record($this->chapterId, LoadState::Busy);
            $this->release($this->secondsUntil($e->busy->retryAt));

            return;
        } catch (ConnectionException) {
            $fetcher->record($this->chapterId, LoadState::Offline);
            $this->release(self::OFFLINE_RETRY_SECONDS);

            return;
        } catch (MangaDexTrouble) {
            $fetcher->record($this->chapterId, LoadState::Retrying);
            $this->release($this->troubleRetrySeconds());

            return;
        } catch (ChapterGone) {
            if ($download) {
                $this->release(0);
            }

            return;
        } catch (ApiError|ChapterUnavailable) {
            $fetcher->record($this->chapterId, LoadState::Unavailable);
            $downloads->ended($this->chapterId);

            return;
        } finally {
            if ($this->tier === 'cache') {
                EnforceCacheCap::dispatch();
            }
        }

        Cache::forget($this->troubleKey());

        if ($stopped()) {
            return;
        }

        if ($fetcher->remaining($this->chapterId) > 0) {
            $fetcher->record($this->chapterId, LoadState::Busy);
            $this->release(Carbon::now()->greaterThanOrEqualTo($until) ? 0 : self::CLAIMED_RETRY_SECONDS);

            return;
        }

        $fetcher->record($this->chapterId, LoadState::Done);
        $downloads->finished($this->chapterId);
    }

    /** Gave up (retryUntil passed): the chapter's download ends too, so a Download all moves on. */
    public function failed(?Throwable $e = null): void
    {
        app(Downloads::class)->ended($this->chapterId);
    }

    /** Counted per chapter: slices and offline waits release the job too, so attempts() can't tell. */
    private function troubleRetrySeconds(): int
    {
        $tries = (int) Cache::get($this->troubleKey(), 0);
        Cache::put($this->troubleKey(), $tries + 1, self::GIVE_UP_AFTER_SECONDS);

        return self::TROUBLE_RETRY_SECONDS[min($tries, count(self::TROUBLE_RETRY_SECONDS) - 1)];
    }

    private function troubleKey(): string
    {
        return "readex.pages.trouble.{$this->chapterId}";
    }

    private function secondsUntil(float $retryAt): int
    {
        return max(1, (int) ceil($retryAt - Carbon::now()->getTimestampMs() / 1000));
    }
}
