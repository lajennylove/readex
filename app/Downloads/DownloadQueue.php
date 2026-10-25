<?php

declare(strict_types=1);

namespace App\Downloads;

use App\Adapters\Http\RateLimit\Priority;
use App\Jobs\DownloadChapter;
use App\Models\Chapter;
use App\Models\Download;
use App\Pages\FetchPages;
use App\Pages\PageFetcher;
use App\Storage\DiskSpaceGuard;
use App\Storage\Exceptions\DiskSpaceLow;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The unified Downloads controller (Plan 21 / feature/0021).
 *
 * Two parallel lanes share one FIFO table:
 *
 * - Background lane: one chapter at a time, pulled by DownloadChapter from
 *   `nextRunnable()` in arrival order (`position` ascending, `queued` state), at
 *   Priority::Bulk. When the row finishes, we dispatch another DownloadChapter so
 *   the chain keeps draining — no cron or supervisor loop needed.
 *
 * - Comodin lane: a reserved slot that sits empty by default. When the user taps
 *   Read on an undownloaded chapter, we pull its row off the queue (if present),
 *   stash the chapter id in the `read_now_lane` singleton, and fire a FetchPages
 *   async task at Priority::Interactive. The rate limiter's priority-aware slot
 *   allocation (see `config/source-limits.php:background`) keeps a slot
 *   permanently out of Bulk's reach so the comodin never waits on background work.
 *   The background lane is NOT paused — both lanes run concurrently.
 *
 * This service is the only writer of `downloads.state`. The old Downloads API
 * (download / downloadAll / stop / remove / finished / ended / isQueued) still
 * works and still writes to the same table; it is now a thin shim that calls into
 * this class for the state transitions it needs.
 */
final class DownloadQueue
{
    public function __construct(
        private readonly PageFetcher $fetcher,
        private readonly DiskSpaceGuard $diskSpace,
    ) {}

    /**
     * Append a Download-all batch to the END of the queue in arrival order.
     * Existing in-progress batches keep their order; this just adds rows after them.
     *
     * @param  list<Chapter>  $chapters  readable chapters in reading order
     * @return int rows newly enqueued (dedupes against rows already present)
     */
    public function enqueueBatch(string $mangaId, string $language, array $chapters, Priority $priority = Priority::Bulk): int
    {
        if ($chapters === []) {
            return 0;
        }

        $existing = Download::query()
            ->whereIn('chapter_id', array_map(fn (Chapter $c) => $c->id, $chapters))
            ->where('state', '!=', QueueState::Done)
            ->pluck('chapter_id')
            ->all();

        $fresh = array_values(array_filter($chapters, fn (Chapter $c) => ! in_array($c->id, $existing, true)));

        if ($fresh === []) {
            return 0;
        }

        $batch = (string) Str::uuid();
        $start = $this->nextPosition();
        $now = Carbon::now();

        Download::query()->insert(array_map(fn (Chapter $chapter, int $offset) => [
            'chapter_id' => $chapter->id,
            'manga_id' => $chapter->manga_id,
            'batch' => $batch,
            'position' => $start + $offset,
            'priority' => $priority->value,
            'state' => QueueState::Queued->value,
            'started_at' => null,
            'finished_at' => null,
            'created_at' => $now,
        ], $fresh, array_keys($fresh)));

        $this->kick();

        return count($fresh);
    }

    /**
     * Append a single chapter to the end of the queue; dedupes if already present
     * (either as a live row or already done).
     */
    public function enqueueOne(string $chapterId, Priority $priority = Priority::Bulk): void
    {
        if (Download::query()->whereKey($chapterId)->exists()) {
            return;
        }

        $chapter = Chapter::query()->find($chapterId);

        if ($chapter === null) {
            return;
        }

        Download::query()->insert([
            'chapter_id' => $chapter->id,
            'manga_id' => $chapter->manga_id,
            'batch' => null,
            'position' => $this->nextPosition(),
            'priority' => $priority->value,
            'state' => QueueState::Queued->value,
            'started_at' => null,
            'finished_at' => null,
            'created_at' => Carbon::now(),
        ]);

        $this->kick();
    }

    /**
     * Reader hit Read on an undownloaded chapter: fill the comodin lane and fire
     * an Interactive-priority FetchPages task. Does NOT pause the background lane.
     *
     * Behaviour by current state:
     *  - Chapter already in the queue (any non-done state) → remove the row so we
     *    don't double-download, then put it on the comodin.
     *  - Chapter not in the queue → just put it on the comodin.
     *  - Chapter is already the comodin's current chapter → no-op (already running).
     *
     * @return ?Chapter the chapter so the caller can open the Reader; null when
     *                  the id is unknown.
     */
    public function readNow(string $chapterId): ?Chapter
    {
        $chapter = Chapter::query()->find($chapterId);

        if ($chapter === null || $chapter->external_url !== null) {
            return $chapter;
        }

        DB::transaction(function () use ($chapterId) {
            // Pull any queued row off the background lane so we don't double-download.
            Download::query()
                ->whereKey($chapterId)
                ->where('state', '!=', QueueState::Done)
                ->delete();

            DB::table('read_now_lane')
                ->where('id', 1)
                ->update(['chapter_id' => $chapterId, 'started_at' => Carbon::now()]);
        });

        $this->fetcher->forgetFailed($chapterId);
        FetchPages::dispatch($chapterId);

        return $chapter;
    }

    /**
     * Clear the comodin lane. Called by FetchPages/PageFetcher when the Interactive
     * fetch for the comodin chapter finishes, or by the UI when the user navigates
     * away. Safe to call when the lane is already idle.
     */
    public function clearReadNow(?string $chapterId = null): void
    {
        $row = DB::table('read_now_lane')->where('id', 1)->first();

        if ($row === null || $row->chapter_id === null) {
            return;
        }

        if ($chapterId !== null && $row->chapter_id !== $chapterId) {
            return;
        }

        DB::table('read_now_lane')->where('id', 1)->update(['chapter_id' => null, 'started_at' => null]);
    }

    public function readNowChapterId(): ?string
    {
        return DB::table('read_now_lane')->where('id', 1)->value('chapter_id');
    }

    /** Transition a row to paused. Only queued rows can pause (downloading rows are mid-slice). */
    public function pause(string $chapterId): void
    {
        Download::query()
            ->whereKey($chapterId)
            ->whereIn('state', [QueueState::Queued->value, QueueState::Downloading->value])
            ->update(['state' => QueueState::Paused->value, 'started_at' => null]);
    }

    public function resume(string $chapterId): void
    {
        Download::query()
            ->whereKey($chapterId)
            ->where('state', QueueState::Paused)
            ->update(['state' => QueueState::Queued->value]);

        $this->kick();
    }

    /**
     * Cancelled chapters are **deleted** from the queue (not kept as `done`), per Jenny's
     * call: a cancelled chapter isn't "done", it's "not going to happen", so excluding it
     * from the denominator ("3 / 54" → "3 / 53") is more honest. A `downloading` row is
     * flagged `canceling` first so the running worker halts between pages; the worker
     * then deletes the row rather than marking it done.
     */
    public function cancel(string $chapterId): void
    {
        $row = Download::query()->whereKey($chapterId)->first();

        if ($row === null || $row->state === QueueState::Done) {
            return;
        }

        if ($row->state === QueueState::Downloading) {
            Download::query()
                ->whereKey($chapterId)
                ->update(['state' => QueueState::Canceling->value]);

            return;
        }

        Download::query()->whereKey($chapterId)->delete();
    }

    /**
     * The ordered list of rows that are not done and not the comodin's current
     * chapter: `queued`, `downloading`, `paused`, `canceling`. Used by the
     * Downloads tab (Phase B).
     *
     * @return EloquentCollection<int, Download>
     */
    public function pending(): EloquentCollection
    {
        $comodin = $this->readNowChapterId();

        return Download::query()
            ->where('state', '!=', QueueState::Done->value)
            ->when($comodin !== null, fn ($q) => $q->where('chapter_id', '!=', $comodin))
            ->orderBy('position')
            ->get();
    }

    /**
     * The oldest `queued` row the background lane can start. Never returns a
     * paused, canceling, downloading or comodin row.
     */
    public function nextRunnable(): ?Download
    {
        $comodin = $this->readNowChapterId();

        return Download::query()
            ->where('state', QueueState::Queued->value)
            ->when($comodin !== null, fn ($q) => $q->where('chapter_id', '!=', $comodin))
            ->orderBy('position')
            ->first();
    }

    /**
     * Count totals for the series' pill in MangaDetail ("Downloading 3 / 54 · 5%").
     * `done` is rows in state=done belonging to the series' latest batch; `total`
     * is the full set of rows for that batch; `percent` is left to the caller /
     * existing `Downloads::running()` which has the page-level math.
     *
     * @return array{queued: int, downloading: int, done: int, total: int}
     */
    public function statusFor(string $mangaId): array
    {
        $rows = Download::query()
            ->where('manga_id', $mangaId)
            ->selectRaw('state, COUNT(*) as n')
            ->groupBy('state')
            ->pluck('n', 'state');

        $queued = (int) ($rows[QueueState::Queued->value] ?? 0);
        $downloading = (int) ($rows[QueueState::Downloading->value] ?? 0);
        $paused = (int) ($rows[QueueState::Paused->value] ?? 0);
        $canceling = (int) ($rows[QueueState::Canceling->value] ?? 0);
        $done = (int) ($rows[QueueState::Done->value] ?? 0);

        return [
            'queued' => $queued,
            'downloading' => $downloading,
            'done' => $done,
            'total' => $queued + $downloading + $paused + $canceling + $done,
        ];
    }

    /**
     * Claim the next runnable row for the background lane. Flips `queued → downloading`
     * atomically so two workers never both claim the same row. Returns the row the
     * caller is now responsible for finishing (or releasing back to `queued`).
     *
     * `DiskSpaceGuard::ensure()` is called at this chapter boundary, matching
     * `CoverStore::download`. If the device is nearly full, we leave the row at
     * `queued` and bail — the next kick retries, and the user sees the
     * "waiting on disk space" state through the DiskSpaceLow handling path.
     *
     * @throws DiskSpaceLow when the device is nearly full; the caller should release
     *                      the job back for retry (same contract as CoverStore::download).
     */
    public function claimNext(): ?Download
    {
        if ($this->nextRunnable() === null) {
            return null;
        }

        $this->diskSpace->ensure();

        return DB::transaction(function () {
            $next = $this->nextRunnable();

            if ($next === null) {
                return null;
            }

            $claimed = Download::query()
                ->whereKey($next->chapter_id)
                ->where('state', QueueState::Queued->value)
                ->update([
                    'state' => QueueState::Downloading->value,
                    'started_at' => Carbon::now(),
                ]);

            if ($claimed !== 1) {
                return null;
            }

            return Download::query()->whereKey($next->chapter_id)->first();
        });
    }

    /** Downloading → done: terminal, chain-kick-next. */
    public function markDone(string $chapterId): void
    {
        Download::query()
            ->whereKey($chapterId)
            ->whereIn('state', [QueueState::Downloading->value, QueueState::Canceling->value, QueueState::Queued->value])
            ->update([
                'state' => QueueState::Done->value,
                'finished_at' => Carbon::now(),
            ]);

        $this->kick();
    }

    /** Downloading → queued: transient failure, DiskSpaceLow, or RequestDeferred. */
    public function release(string $chapterId): void
    {
        Download::query()
            ->whereKey($chapterId)
            ->where('state', QueueState::Downloading->value)
            ->update([
                'state' => QueueState::Queued->value,
                'started_at' => null,
            ]);
    }

    public function isCanceling(string $chapterId): bool
    {
        return Download::query()
            ->whereKey($chapterId)
            ->where('state', QueueState::Canceling->value)
            ->exists();
    }

    /**
     * Dispatch a DownloadChapter to drain the next runnable row — but ONLY when the
     * background lane is idle. If a chapter is already `downloading`, that job will
     * chain-kick the next one itself when it finishes; a second dispatch here would
     * double-pick the queue and surface as spurious extra jobs in tests that count
     * `Queue::assertPushed(DownloadChapter::class, N)`.
     */
    public function kick(): void
    {
        if (Download::query()->where('state', QueueState::Downloading->value)->exists()) {
            return;
        }

        $next = $this->nextRunnable();

        if ($next === null) {
            return;
        }

        DownloadChapter::dispatch($next->chapter_id, 'local', Priority::from($next->priority));
    }

    private function nextPosition(): int
    {
        return ((int) Download::query()->max('position')) + 1;
    }
}
