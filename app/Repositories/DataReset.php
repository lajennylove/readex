<?php

namespace App\Repositories;

use App\Models\Chapter;
use App\Models\Collection as LibraryEntry;
use App\Models\Manga;
use App\Models\ReadingProgress;
use App\Models\StoredChapter;
use Illuminate\Support\Facades\DB;

final class DataReset
{
    /** Saved MangaDex answers that go away entirely (§7.1). */
    private const TABLES = ['manga_lists', 'tags', 'statistics', 'recommendations', 'at_home', 'chapter_feeds'];

    /**
     * "Refresh MangaDex data" clearing step (§7.1, D20, Review Focus 5). Chapters of Library
     * series, plus any stored or started chapter, and their manga stay with `fetched_at = null`;
     * every other MangaDex row goes. Settings, collections, progress and stored chapters (and
     * the controller's rate buckets) aren't touched. The refill is Phase 4 (`RefreshAllData`).
     */
    public function clearRemoteData(): void
    {
        DB::transaction(function () {
            foreach (self::TABLES as $table) {
                DB::table($table)->delete();
            }

            Chapter::query()
                ->whereNotIn('manga_id', LibraryEntry::query()->select('manga_id'))
                ->whereNotIn('id', StoredChapter::query()->select('chapter_id'))
                ->whereNotIn('id', ReadingProgress::query()->select('chapter_id'))
                ->delete();
            Chapter::query()->update(['fetched_at' => null]);

            Manga::query()
                ->whereNotIn('id', LibraryEntry::query()->select('manga_id'))
                ->whereNotIn('id', Chapter::query()->select('manga_id'))
                ->delete();
            Manga::query()->update(['fetched_at' => null, 'recommendations_fetched_at' => null]);
        });
    }
}
