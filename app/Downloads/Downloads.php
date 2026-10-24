<?php

namespace App\Downloads;

use App\Jobs\DownloadChapter;
use App\MangaDex\RateLimit\Priority;
use App\Models\Chapter;
use App\Models\Download;
use App\Models\StoredChapter;
use App\Pages\PageFetcher;
use App\Repositories\LibraryChapter;
use App\Repositories\LibraryRepository;
use App\Storage\PageStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Downloads (spec §11.3, D34, D35 11–12): chapters kept in the local tier, which the cache cap
 * never touches. The queue is the `downloads` table, shared by the UI, the queue worker and the
 * async lane; it goes by chapter id, so it doesn't matter which DownloadChapter finishes a
 * chapter (the unique lock drops a download's dispatch while the reader's cache job runs, and
 * that job then writes into the local tier). A Download all is one batch run one chapter at a
 * time: the next is dispatched when the current one ends, so a chapter opened meanwhile isn't
 * stuck behind the whole series on the phone's queue.
 */
final class Downloads
{
    public function __construct(
        private readonly PageStore $pages,
        private readonly LibraryRepository $library,
        private readonly PageFetcher $fetcher,
    ) {}

    /**
     * Download one chapter, on any connection (D35 12): moved when it's all in the cache, else
     * moved with what it has and the rest queued in the quality it started with.
     */
    public function download(string $chapterId, Priority $priority = Priority::Prefetch): void
    {
        $chapter = Chapter::query()->find($chapterId);

        if ($chapter === null || $chapter->external_url !== null || $this->isQueued($chapterId)
            || $this->status($chapterId)->state === DownloadState::Downloaded) {
            return;
        }

        $this->library->addChapter($chapterId);
        $this->enqueue([$chapter], null, $priority, started: true);

        if ($this->start($chapterId, $priority)) {
            $this->finish($chapterId);
        }
    }

    /**
     * Download all: the plan's chapters as one batch at bulk priority, only the first one
     * started. Nothing while a batch of the series runs.
     *
     * @return int chapters queued
     */
    public function downloadAll(string $mangaId, string $language): int
    {
        $chapters = $this->plan($mangaId, $language)['chapters'];

        if ($chapters->isEmpty() || $this->running($mangaId) !== null) {
            return 0;
        }

        $this->library->addChapter($chapters->first()->id);
        $batch = (string) Str::uuid();
        $this->enqueue($chapters->all(), $batch, Priority::Bulk, started: false);
        $this->startNext($batch);

        return $chapters->count();
    }

    /**
     * What Download all covers in a language: the readable chapters, one upload per number as the
     * Library picks it (`LibraryRepository::sections()`), in reading order. `chapters` leaves out
     * those already downloaded or in the queue.
     *
     * @return array{readable: int, downloaded: int, chapters: Collection<int, Chapter>}
     */
    public function plan(string $mangaId, string $language): array
    {
        $readable = collect($this->library->sections($mangaId, $language))
            ->flatMap(fn (array $section) => $section['chapters'])
            ->map(fn (LibraryChapter $c) => $c->chapter)
            ->filter(fn (Chapter $c) => $c->external_url === null)
            ->values();
        $ids = $readable->pluck('id');
        $downloaded = StoredChapter::query()->whereIn('chapter_id', $ids)->where('tier', 'local')->where('status', 'done')->pluck('chapter_id');
        $queued = Download::query()->whereIn('chapter_id', $ids)->whereNull('finished_at')->pluck('chapter_id');

        return [
            'readable' => $readable->count(),
            'downloaded' => $downloaded->count(),
            'chapters' => $readable->reject(fn (Chapter $c) => $downloaded->contains($c->id) || $queued->contains($c->id))->values(),
        ];
    }

    /**
     * The series' Download all while it runs: chapters ended, chapters in it, and stored pages
     * over its page count (floored; a chapter's count once begun, else the feed's).
     *
     * @return ?array{done: int, total: int, percent: int}
     */
    public function running(string $mangaId): ?array
    {
        $batch = Download::query()->where('manga_id', $mangaId)->whereNotNull('batch')->whereNull('finished_at')->value('batch');

        if ($batch === null) {
            return null;
        }

        $rows = DB::table('downloads')->where('batch', $batch)
            ->leftJoin('stored_chapters', 'stored_chapters.chapter_id', '=', 'downloads.chapter_id')
            ->leftJoin('chapters', 'chapters.id', '=', 'downloads.chapter_id')
            ->get(['downloads.finished_at', 'stored_chapters.tier', 'stored_chapters.pages_done', 'stored_chapters.page_count', 'chapters.pages']);
        $pages = $rows->sum(fn (object $row) => self::pageCount($row));

        return [
            'done' => $rows->whereNotNull('finished_at')->count(),
            'total' => $rows->count(),
            'percent' => $pages > 0 ? intdiv((int) $rows->sum('pages_done') * 100, $pages) : 0,
        ];
    }

    /** Stop download: out of the queue; its job stops at the next page, the pages stored stay. */
    public function stop(string $chapterId): void
    {
        $entry = $this->entry($chapterId);

        if ($entry === null) {
            return;
        }

        $entry->delete();

        if ($entry->batch !== null && $entry->started_at !== null) {
            $this->startNext($entry->batch);
        }
    }

    /** Stop a Download all: the chapters not started go, the ended ones stay, the current one stays partial. */
    public function stopAll(string $mangaId): void
    {
        Download::query()->where('manga_id', $mangaId)->whereNotNull('batch')->whereNull('finished_at')->delete();
    }

    /** Remove download (D35 11): back to the cache tier, progress untouched. Not while it's in the queue. */
    public function remove(string $chapterId): void
    {
        if (! $this->isQueued($chapterId)) {
            $this->pages->demote($chapterId);
        }
    }

    /** Every page arrived: in the queue, it's a download now, whichever job got the pages. */
    public function finished(string $chapterId): void
    {
        if ($this->isQueued($chapterId)) {
            $this->pages->promote($chapterId);
        }

        $this->ended($chapterId);
    }

    /** Done, unavailable or failed for good: the entry ends; a batch's current chapter starts the next one. */
    public function ended(string $chapterId): void
    {
        $entry = $this->entry($chapterId);

        if ($entry === null || ! $this->finish($chapterId)) {
            return;
        }

        if ($entry->batch !== null && $entry->started_at !== null) {
            $this->startNext($entry->batch);
        }
    }

    public function isQueued(string $chapterId): bool
    {
        return Download::query()->whereKey($chapterId)->whereNull('finished_at')->exists();
    }

    public function status(string $chapterId): DownloadStatus
    {
        return $this->statuses([$chapterId])[$chapterId];
    }

    /**
     * One query for every row of a chapter list (700 rows re-render on every poll).
     *
     * @param  list<string>  $chapterIds
     * @return array<string, DownloadStatus>
     */
    public function statuses(array $chapterIds): array
    {
        $rows = DB::table('chapters')->whereIn('chapters.id', $chapterIds)
            ->leftJoin('stored_chapters', 'stored_chapters.chapter_id', '=', 'chapters.id')
            ->leftJoin('downloads', fn ($join) => $join->on('downloads.chapter_id', '=', 'chapters.id')->whereNull('downloads.finished_at'))
            ->where(fn ($q) => $q->where('stored_chapters.tier', 'local')->orWhereNotNull('downloads.chapter_id'))
            ->get(['chapters.id', 'stored_chapters.tier', 'stored_chapters.status', 'stored_chapters.pages_done', 'stored_chapters.page_count', 'downloads.chapter_id as queued', 'downloads.started_at'])
            ->keyBy('id');

        $statuses = [];

        foreach ($chapterIds as $id) {
            $statuses[$id] = self::statusOf($rows->get($id));
        }

        return $statuses;
    }

    private static function statusOf(?object $row): DownloadStatus
    {
        if ($row === null) {
            return new DownloadStatus(DownloadState::None);
        }

        // Downloading and Stopped are begun local chapters with a page stored or missing: never 0 pages.
        $percent = fn () => intdiv((int) $row->pages_done * 100, (int) $row->page_count);

        return match (true) {
            $row->queued !== null && $row->started_at !== null && $row->tier === 'local' && $row->pages_done > 0 => new DownloadStatus(DownloadState::Downloading, $percent()),
            $row->queued !== null => new DownloadStatus(DownloadState::Queued),
            $row->status === 'done' => new DownloadStatus(DownloadState::Downloaded),
            default => new DownloadStatus(DownloadState::Stopped, $percent()),
        };
    }

    /** The stored chapter's page count once begun (the @Home manifest), else the feed's estimate. */
    private static function pageCount(object $row): int
    {
        return (int) ($row->tier === null ? $row->pages : $row->page_count);
    }

    private function entry(string $chapterId): ?Download
    {
        return Download::query()->whereKey($chapterId)->whereNull('finished_at')->first();
    }

    /** @param  list<Chapter>  $chapters  in reading order */
    private function enqueue(array $chapters, ?string $batch, Priority $priority, bool $started): void
    {
        $now = Carbon::now();

        Download::query()->upsert(array_map(fn (Chapter $chapter, int $position) => [
            'chapter_id' => $chapter->id,
            'manga_id' => $chapter->manga_id,
            'batch' => $batch,
            'position' => $position,
            'priority' => $priority->value,
            'started_at' => $started ? $now : null,
            'finished_at' => null,
            'created_at' => $now,
        ], $chapters, array_keys($chapters)), ['chapter_id']);
    }

    /** The batch's next chapter not started, claimed so two runtimes never both start it; chapters all on the phone just move. */
    private function startNext(string $batch): void
    {
        while (($next = Download::query()->where('batch', $batch)->whereNull('started_at')->whereNull('finished_at')->orderBy('position')->first()) !== null) {
            if (Download::query()->whereKey($next->chapter_id)->whereNull('started_at')->update(['started_at' => Carbon::now()]) !== 1) {
                return;
            }

            if (! $this->start($next->chapter_id, Priority::from($next->priority))) {
                return;
            }

            $this->finish($next->chapter_id);
        }
    }

    /**
     * A chapter the cap evicts between the check and the move gets its job like one never stored.
     * Pages that failed are asked for again: a download the user starts is a retry.
     *
     * @return bool the chapter was all on the phone: moved, nothing to download
     */
    private function start(string $chapterId, Priority $priority): bool
    {
        $this->fetcher->forgetFailed($chapterId);
        $complete = $this->pages->has($chapterId);

        if ($this->pages->promote($chapterId) && $complete) {
            return true;
        }

        DownloadChapter::dispatch($chapterId, 'local', $priority);

        return false;
    }

    private function finish(string $chapterId): bool
    {
        return Download::query()->whereKey($chapterId)->whereNull('finished_at')->update(['finished_at' => Carbon::now()]) === 1;
    }
}
