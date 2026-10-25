<?php

namespace App\Repositories;

use App\Models\Chapter;
use App\Models\Collection as LibraryEntry;
use App\Models\Manga;
use App\Models\ReadingProgress;
use App\Models\StoredChapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class LibraryRepository
{
    /** Library filter chips (§5.7). */
    public const FILTERS = ['all', 'reading', 'downloaded', 'favorites'];

    public function add(string $mangaId): LibraryEntry
    {
        return LibraryEntry::query()->firstOrCreate(['manga_id' => $mangaId], ['created_at' => Carbon::now()]);
    }

    public function has(string $mangaId): bool
    {
        return LibraryEntry::query()->whereKey($mangaId)->exists();
    }

    /**
     * Takes a series out of the Library (Library tile long-press, confirmed). Only the
     * `collections` row goes — reading progress and stored chapters stay, so the series picks
     * up where it left off if it's added back.
     */
    public function removeFromLibrary(string $mangaId): void
    {
        LibraryEntry::query()->whereKey($mangaId)->delete();
    }

    /**
     * Reading or downloading a chapter puts its series in the Library. The first such chapter
     * picks the preferred group (§11.3); later ones don't change it.
     */
    public function addChapter(string $chapterId): LibraryEntry
    {
        $chapter = Chapter::query()->findOrFail($chapterId);
        $entry = $this->add($chapter->manga_id);

        if ($entry->preferred_group_id === null && $chapter->group_id !== null) {
            $entry->update(['preferred_group_id' => $chapter->group_id]);
        }

        return $entry;
    }

    public function setFavorite(string $mangaId, bool $favorite): void
    {
        $this->add($mangaId)->update(['favorite' => $favorite]);
    }

    public function preferredGroup(string $mangaId): ?string
    {
        return LibraryEntry::query()->find($mangaId)?->preferred_group_id;
    }

    public function setPreferredGroup(string $mangaId, string $groupId): void
    {
        $this->add($mangaId)->update(['preferred_group_id' => $groupId]);
    }

    /**
     * `$page` counts from 0. Reaching the last page marks the chapter finished; it stays
     * finished when it's read again.
     */
    public function saveProgress(string $chapterId, int $page, int $pageCount): ReadingProgress
    {
        if ($pageCount < 1 || $page < 0 || $page >= $pageCount) {
            throw new InvalidArgumentException("Page {$page} is outside 0–".($pageCount - 1).'.');
        }

        return DB::transaction(function () use ($chapterId, $page, $pageCount) {
            $mangaId = $this->addChapter($chapterId)->manga_id;
            $now = Carbon::now();

            $progress = ReadingProgress::query()->firstOrNew(['chapter_id' => $chapterId]);
            $progress->fill(['manga_id' => $mangaId, 'page' => $page, 'page_count' => $pageCount, 'updated_at' => $now]);

            if ($page === $pageCount - 1) {
                $progress->finished_at ??= $now;
            }

            $progress->save();

            return $progress;
        });
    }

    public function progressFor(string $chapterId): ?ReadingProgress
    {
        return ReadingProgress::query()->find($chapterId);
    }

    /**
     * Library grid, last read first (series never opened: by date added).
     *
     * @return Collection<int, Manga>
     */
    public function collections(string $filter = 'all'): Collection
    {
        if (! in_array($filter, self::FILTERS, true)) {
            throw new InvalidArgumentException("Unknown Library filter [{$filter}].");
        }

        $lastRead = ReadingProgress::query()->selectRaw('manga_id, max(updated_at) as last_read')->groupBy('manga_id');
        $downloaded = Chapter::query()->select('manga_id')->whereIn('id', StoredChapter::query()
            ->select('chapter_id')->where('status', 'done')->whereIn('tier', ['local', 'icloud']));

        $ids = LibraryEntry::query()
            ->leftJoinSub($lastRead, 'p', 'p.manga_id', '=', 'collections.manga_id')
            ->when($filter === 'reading', fn ($q) => $q->whereNotNull('p.last_read'))
            ->when($filter === 'downloaded', fn ($q) => $q->whereIn('collections.manga_id', $downloaded))
            ->when($filter === 'favorites', fn ($q) => $q->where('favorite', true))
            ->orderByRaw('coalesce(p.last_read, collections.created_at) desc')
            ->orderBy('collections.manga_id')
            ->pluck('collections.manga_id');

        if ($filter === 'reading') {
            $ids = $ids->filter(fn (string $id) => $this->resume($id) !== null)->values();
        }

        $manga = Manga::query()->whereIn('id', $ids)->get()->keyBy('id');

        return $ids->map(fn (string $id) => $manga->get($id))->filter()->values();
    }

    /**
     * Collection screen (§11.3): volume sections like the chapter list, one upload per number.
     * Includes chapters the feed no longer has (`fetched_at = null`: stored, or kept by DataReset).
     * Upload per number: the preferred group's; else one you read or stored; else the first in
     * reading order. Those last two are marked `otherGroup` when a preferred group is set.
     *
     * @return list<array{volume: ?string, chapters: Collection<int, LibraryChapter>}>
     */
    public function sections(string $mangaId, string $language): array
    {
        $chapters = Chapter::query()->where('manga_id', $mangaId)->where('language', $language)->get()
            ->sort(ChapterRepository::compare(...))->values();
        $progress = ReadingProgress::query()->whereIn('chapter_id', $chapters->pluck('id'))->get()->keyBy('chapter_id');
        $stored = StoredChapter::query()->whereIn('chapter_id', $chapters->pluck('id'))->pluck('chapter_id');
        $preferred = $this->preferredGroup($mangaId);

        $picked = $chapters
            ->groupBy(fn (Chapter $c) => $c->number ?? $c->id)
            ->map(fn (Collection $uploads) => $uploads->first(fn (Chapter $c) => $preferred !== null && $c->group_id === $preferred)
                ?? $uploads->first(fn (Chapter $c) => $progress->has($c->id) || $stored->contains($c->id))
                ?? $uploads->first())
            ->keyBy('id');

        // Grouped from every upload, so a number lands in the volume any upload has.
        return array_map(fn (array $section) => [
            'volume' => $section['volume'],
            'chapters' => $section['chapters']
                ->filter(fn (Chapter $c) => $picked->has($c->id))
                ->map(fn (Chapter $c) => new LibraryChapter($c, $progress->get($c->id), $preferred !== null && $c->group_id !== $preferred))
                ->values(),
        ], ChapterRepository::group($chapters));
    }

    /**
     * Where a series picks up (Detail / Collection "Continue" button): the last chapter read on
     * its saved page; once that one is finished, the next chapter in Library order (same
     * language), on its own saved page if it has one (else `progress = null`, from the start).
     * Null when never read, or caught up.
     */
    public function resume(string $mangaId): ?ContinueReading
    {
        $progress = ReadingProgress::query()->where('manga_id', $mangaId)
            ->orderByDesc('updated_at')->orderBy('chapter_id')->first();

        return $progress === null ? null : $this->tile($progress);
    }

    /**
     * The resume bar (D31, D33 choice 8): `resume()` for the series read last. Null when nothing
     * was read or that series is caught up; never another series.
     */
    public function lastOpened(): ?ContinueReading
    {
        $progress = ReadingProgress::query()->orderByDesc('updated_at')->orderBy('chapter_id')->first();

        return $progress === null ? null : $this->tile($progress);
    }

    /**
     * Home "Continue reading": `resume()` for every started series, last read first; caught-up
     * series are left out.
     *
     * @return Collection<int, ContinueReading>
     */
    public function continueReading(int $limit = 20): Collection
    {
        $tiles = collect();
        $latest = ReadingProgress::query()->orderByDesc('updated_at')->orderBy('chapter_id')->get()->unique('manga_id');

        foreach ($latest as $progress) {
            $tile = $this->tile($progress);

            if ($tile !== null) {
                $tiles->push($tile);
            }

            if ($tiles->count() === $limit) {
                break;
            }
        }

        return $tiles;
    }

    private function tile(ReadingProgress $progress): ?ContinueReading
    {
        $chapter = Chapter::query()->find($progress->chapter_id);
        $manga = Manga::query()->find($progress->manga_id);

        if ($chapter === null || $manga === null) {
            return null;
        }

        return $progress->finished_at === null
            ? new ContinueReading($manga, $chapter, $progress)
            : $this->next($manga, $chapter);
    }

    /**
     * The chapter after this one in Library order (same language, one upload per number, the
     * preferred group's first); null at the end. Reader's "Next" button.
     */
    public function after(Chapter $chapter): ?Chapter
    {
        return $this->following($chapter)?->chapter;
    }

    private function next(Manga $manga, Chapter $chapter): ?ContinueReading
    {
        $next = $this->following($chapter);

        return $next === null ? null : new ContinueReading($manga, $next->chapter, $next->progress);
    }

    private function following(Chapter $chapter): ?LibraryChapter
    {
        $order = collect($this->sections($chapter->manga_id, $chapter->language))->flatMap(fn (array $s) => $s['chapters'])->values();
        $key = $chapter->number ?? $chapter->id;
        $at = $order->search(fn (LibraryChapter $c) => ($c->chapter->number ?? $c->chapter->id) === $key);

        return $at === false ? null : $order->get($at + 1);
    }
}
