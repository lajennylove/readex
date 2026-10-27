<?php

use App\Repositories\FactoryReset;
use App\Repositories\LibraryRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\SourceRepository;
use App\Repositories\ViewedSeriesRepository;
use App\Storage\PageStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Covers;
use Tests\Support\Stored;

/**
 * Plan-08 Task 4-- "a full destructible restore ... delete all the
 * downloads, caches, settings in the database, everything ... restore the app to factory
 * settings (as just installed new)". Distinct from DataReset::clearRemoteData() (§7.1), which
 * deliberately keeps the Library/progress/downloads/settings — this keeps nothing.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-27 12:00:00');
    $this->caches = Covers::useTempCaches();
    $this->storage = Stored::useTempStorage();
});

it('deletes every table\'s rows', function () {
    app(SettingsRepository::class)->setLanguage('es-la');
    app(SettingsRepository::class)->markSetupDone();
    app(SettingsRepository::class)->acknowledgeEula();
    Http::fake(['https://example.test' => Http::response('', 200)]);
    app(SourceRepository::class)->add('Mine', 'https://example.test');
    Stored::chapter('fr-a');
    app(LibraryRepository::class)->addChapter('fr-a');
    app(LibraryRepository::class)->saveProgress('fr-a', 0, 2);
    app(ViewedSeriesRepository::class)->recordView('fr-a');

    expect(DB::table('settings')->count())->toBeGreaterThan(0)
        ->and(DB::table('sources')->count())->toBeGreaterThan(0)
        ->and(DB::table('collections')->count())->toBeGreaterThan(0)
        ->and(DB::table('reading_progress')->count())->toBeGreaterThan(0)
        ->and(DB::table('stored_chapters')->count())->toBeGreaterThan(0)
        ->and(DB::table('viewed_series')->count())->toBeGreaterThan(0)
        ->and(DB::table('chapters')->count())->toBeGreaterThan(0);

    app(FactoryReset::class)->wipe();

    foreach (['settings', 'sources', 'collections', 'reading_progress', 'stored_chapters', 'viewed_series', 'chapters', 'manga', 'manga_lists', 'tags', 'statistics', 'recommendations', 'at_home', 'chapter_feeds', 'downloads', 'rate_buckets', 'api_usage'] as $table) {
        expect(DB::table($table)->count())->toBe(0, "{$table} should be empty after a factory reset");
    }
});

it('deletes the cache tier and the downloads folder on disk', function () {
    Stored::chapter('fr-cache');
    Stored::chapter('fr-local', tier: 'local');
    Covers::store($this->caches, Covers::manga('fr-cover'));

    expect(is_dir($this->caches))->toBeTrue()
        ->and(is_dir(storage_path('app/public/downloads')))->toBeTrue();

    app(FactoryReset::class)->wipe();

    expect(is_dir($this->caches))->toBeFalse()
        ->and(is_dir(storage_path('app/public/downloads')))->toBeFalse()
        ->and(app(PageStore::class)->has('fr-cache'))->toBeFalse()
        ->and(app(PageStore::class)->has('fr-local'))->toBeFalse();
});

it('leaves setup_done and eula_acknowledged false afterwards, same as a true fresh install', function () {
    app(SettingsRepository::class)->markSetupDone();
    app(SettingsRepository::class)->acknowledgeEula();

    app(FactoryReset::class)->wipe();

    expect(app(SettingsRepository::class)->setupDone())->toBeFalse()
        ->and(app(SettingsRepository::class)->eulaAcknowledged())->toBeFalse();
});
