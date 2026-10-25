<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Manga;
use App\Models\ViewedSeries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "You may still be interested" row (D41/D42): a series lands here the moment its Detail screen
 * is mounted (`MangaDetail::mount()`), independent of `collections` (favorited) and
 * `reading_progress` (read). Capped at 10, oldest by `last_viewed_at` evicted; removed the
 * moment the series is favorited or any of its chapters is read, or by a manual
 * "Remove from this list" long-press. A later Detail visit re-inserts a fresh row.
 */
final class ViewedSeriesRepository
{
    public const CAP = 10;

    /** Detail mount: first view sets both timestamps, a later one only bumps `last_viewed_at`. */
    public function recordView(string $mangaId): void
    {
        $now = Carbon::now();

        DB::transaction(function () use ($mangaId, $now) {
            $row = ViewedSeries::query()->find($mangaId);

            if ($row !== null) {
                $row->update(['last_viewed_at' => $now]);

                return;
            }

            ViewedSeries::query()->create(['manga_id' => $mangaId, 'first_viewed_at' => $now, 'last_viewed_at' => $now]);

            $overflow = ViewedSeries::query()->count() - self::CAP;

            if ($overflow > 0) {
                ViewedSeries::query()->orderBy('last_viewed_at')->orderBy('manga_id')->limit($overflow)->get()
                    ->each(fn (ViewedSeries $oldest) => $oldest->delete());
            }
        });
    }

    /** Trigger 1 (D42): favoriting a series promotes it out of this row at once. */
    public function removeOnFavorite(string $mangaId): void
    {
        $this->remove($mangaId);
    }

    /** Trigger 2 (D42): reading any chapter of the series promotes it out of this row at once. */
    public function removeOnRead(string $mangaId): void
    {
        $this->remove($mangaId);
    }

    /** Long-press "Remove from this list" — same effect as the automatic triggers. */
    public function remove(string $mangaId): void
    {
        ViewedSeries::query()->whereKey($mangaId)->delete();
    }

    /**
     * Most-recently-viewed first (D41), excluding any series the user has already committed to:
     * anything in `collections` (favorited, pinned, read, or downloaded all route through
     * `LibraryRepository::add`) is dropped. A series the user glanced at before favoriting from
     * somewhere else — or downloaded a chapter of without ever visiting Detail again — is still
     * in `viewed_series` as a stale row; it's filtered at read time rather than racing the
     * removeOn* events, so the row is correct regardless of event order. The stale rows are
     * harmless (CAP of 10 keeps the table small). Also drops any manga whose row is gone.
     *
     * @return Collection<int, Manga>
     */
    public function list(): Collection
    {
        $ids = ViewedSeries::query()
            ->whereNotIn('manga_id', DB::table('collections')->select('manga_id'))
            ->orderByDesc('last_viewed_at')
            ->orderByDesc('manga_id')
            ->pluck('manga_id');
        $manga = Manga::query()->whereIn('id', $ids)->get()->keyBy('id');

        return $ids->map(fn (string $id) => $manga->get($id))->filter()->values();
    }
}
