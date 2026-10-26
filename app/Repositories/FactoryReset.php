<?php

namespace App\Repositories;

use App\Storage\StorageBridge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Plan-08 Task 4 (Jenny 2026-10-24 18:29 EDT): "a full destructible restore ... delete all the
 * downloads, caches, settings in the database, everything ... restore the app to factory
 * settings (as just installed new)". Unlike `DataReset::clearRemoteData()` (§7.1, "Refresh
 * provider data" — keeps the Library, progress, downloads, settings), this clears absolutely
 * everything: every table and every file on disk, the whole SQLite database back to empty, so
 * the next launch is indistinguishable from a true fresh install (EULA, then Setup, again).
 */
final class FactoryReset
{
    /** Every data table (not `users`/`cache`/`jobs`, which are framework plumbing, not app data). */
    private const TABLES = [
        'rate_buckets', 'api_usage',
        'tags', 'manga', 'manga_lists', 'chapters', 'chapter_feeds', 'statistics', 'recommendations', 'at_home',
        'settings', 'collections', 'reading_progress', 'stored_chapters', 'viewed_series',
        'downloads',
    ];

    public function __construct(private readonly StorageBridge $bridge) {}

    public function wipe(): void
    {
        DB::transaction(function () {
            foreach (self::TABLES as $table) {
                DB::table($table)->delete();
            }
        });

        File::deleteDirectory(rtrim($this->bridge->cachesPath(), '/'));
        File::deleteDirectory(storage_path('app/public/downloads'));
    }
}
