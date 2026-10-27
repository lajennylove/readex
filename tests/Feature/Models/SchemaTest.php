<?php

use App\Models\AtHome;
use App\Models\Chapter;
use App\Models\Collection;
use App\Models\Manga;
use App\Models\ReadingProgress;
use App\Models\Setting;
use App\Models\Statistic;
use App\Models\Tag;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates every spec §6 table plus chapter_feeds', function (string $table, array $columns) {
    expect(Schema::hasTable($table))->toBeTrue()
        ->and(Schema::hasColumns($table, $columns))->toBeTrue();
})->with([
    ['tags', ['id', 'group', 'name', 'fetched_at']],
    ['manga', ['id', 'title', 'alt_titles', 'description', 'status', 'year', 'content_rating', 'available_languages', 'original_language', 'tag_ids', 'cover_file', 'authors', 'artists', 'fetched_at', 'recommendations_fetched_at']],
    ['manga_lists', ['list_key', 'batch', 'position', 'manga_id', 'chapter_id', 'fetched_at']],
    ['chapters', ['id', 'manga_id', 'volume', 'number', 'title', 'language', 'group_id', 'group_name', 'pages', 'external_url', 'readable_at', 'fetched_at']],
    ['chapter_feeds', ['manga_id', 'language', 'total', 'fetched_at']],
    ['statistics', ['manga_id', 'rating_avg', 'rating_bayesian', 'follows', 'distribution', 'fetched_at']],
    ['recommendations', ['manga_id', 'recommended_id', 'position', 'score', 'fetched_at']],
    ['at_home', ['chapter_id', 'base_url', 'hash', 'data_files', 'data_saver_files', 'fetched_at']],
    ['settings', ['key', 'value']],
    ['collections', ['manga_id', 'favorite', 'preferred_group_id', 'paused', 'created_at']],
    ['reading_progress', ['chapter_id', 'manga_id', 'page', 'page_count', 'finished_at', 'updated_at']],
    ['stored_chapters', ['chapter_id', 'tier', 'quality', 'status', 'pages_done', 'page_count', 'bytes', 'last_opened_at', 'error']],
    ['downloads', ['chapter_id', 'manga_id', 'batch', 'position', 'priority', 'started_at', 'finished_at', 'created_at']],
]);

it('round-trips JSON columns and immutable dates on manga', function () {
    Carbon::setTestNow('2026-10-24 12:00:00');

    Manga::create([
        'id' => 'a1',
        'title' => ['en' => 'Return to Wonder', 'ja-ro' => 'Wandaa'],
        'alt_titles' => [['es-la' => 'Regreso']],
        'description' => ['en' => 'Text', 'es-la' => 'Texto'],
        'status' => 'ongoing',
        'year' => 2021,
        'content_rating' => 'safe',
        'available_languages' => ['en', 'es-la'],
        'original_language' => 'ko',
        'tag_ids' => ['t1', 't2'],
        'cover_file' => 'c.jpg',
        'fetched_at' => Carbon::now(),
    ]);

    $manga = Manga::find('a1');

    expect($manga->title)->toBe(['en' => 'Return to Wonder', 'ja-ro' => 'Wandaa'])
        ->and($manga->alt_titles)->toBe([['es-la' => 'Regreso']])
        ->and($manga->available_languages)->toBe(['en', 'es-la'])
        ->and($manga->year)->toBe(2021)
        ->and($manga->fetched_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($manga->fetched_at->toDateTimeString())->toBe('2026-10-24 12:00:00');
});

it('keeps an empty description as an empty array', function () {
    Tag::create(['id' => 't1', 'group' => 'genre', 'name' => ['en' => 'Action'], 'fetched_at' => null]);
    Statistic::create(['manga_id' => 'a1', 'distribution' => [], 'follows' => 3]);

    expect(Tag::find('t1')->name)->toBe(['en' => 'Action'])
        ->and(Statistic::find('a1')->distribution)->toBe([])
        ->and(Statistic::find('a1')->rating_avg)->toBeNull();
});

it('stores scalar and list settings as JSON', function () {
    Setting::create(['key' => 'language', 'value' => 'es-la']);
    Setting::create(['key' => 'content_ratings', 'value' => ['safe', 'suggestive']]);
    Setting::create(['key' => 'setup_done', 'value' => true]);

    expect(Setting::find('language')->value)->toBe('es-la')
        ->and(Setting::find('content_ratings')->value)->toBe(['safe', 'suggestive'])
        ->and(Setting::find('setup_done')->value)->toBeTrue();
});

it('sets only created_at on collections and only updated_at on reading progress', function () {
    Carbon::setTestNow('2026-10-24 08:00:00');

    $collection = Collection::create(['manga_id' => 'a1']);
    $progress = ReadingProgress::create(['chapter_id' => 'c1', 'manga_id' => 'a1', 'page' => 3, 'page_count' => 20]);

    expect($collection->fresh()->created_at->toDateTimeString())->toBe('2026-10-24 08:00:00')
        ->and($collection->fresh()->favorite)->toBeFalse()
        ->and($progress->fresh()->updated_at->toDateTimeString())->toBe('2026-10-24 08:00:00');
});

it('rejects a manga twice in the same list but allows it in another list', function () {
    $row = fn (string $key, int $position) => ['list_key' => $key, 'batch' => 1, 'position' => $position, 'manga_id' => 'a1', 'fetched_at' => now()];

    DB::table('manga_lists')->insert($row('home.season', 0));
    DB::table('manga_lists')->insert($row('tag:t1', 0));

    expect(fn () => DB::table('manga_lists')->insert($row('home.season', 1)))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('keys chapter feeds by manga and language', function () {
    DB::table('chapter_feeds')->insert(['manga_id' => 'a1', 'language' => 'es-la']);
    DB::table('chapter_feeds')->insert(['manga_id' => 'a1', 'language' => 'en']);

    expect(fn () => DB::table('chapter_feeds')->insert(['manga_id' => 'a1', 'language' => 'en']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('keys recommendations by manga and recommended manga', function () {
    $row = ['manga_id' => 'a1', 'recommended_id' => 'b1', 'position' => 0, 'score' => 0.9, 'fetched_at' => now()];

    DB::table('recommendations')->insert($row);

    expect(fn () => DB::table('recommendations')->insert($row))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('round-trips @Home file lists and chapter dates', function () {
    AtHome::create(['chapter_id' => 'c1', 'base_url' => 'https://x.source.network.test', 'hash' => 'h', 'data_files' => ['1.png'], 'data_saver_files' => ['1.jpg'], 'fetched_at' => now()]);
    Chapter::create(['id' => 'c1', 'manga_id' => 'a1', 'number' => '82.1', 'volume' => null, 'language' => 'es-la', 'readable_at' => '2026-10-01 10:00:00']);

    expect(AtHome::find('c1')->data_saver_files)->toBe(['1.jpg'])
        ->and(Chapter::find('c1')->number)->toBe('82.1')
        ->and(Chapter::find('c1')->volume)->toBeNull()
        ->and(Chapter::find('c1')->pages)->toBe(0)
        ->and(Chapter::find('c1')->readable_at)->toBeInstanceOf(CarbonImmutable::class);
});

it('has refresh ages matching spec §7', function () {
    expect(config('readex.refresh_ages'))->toBe([
        'tags' => 2592000,
        'home.season' => 86400,
        'home.popular' => 21600,
        'home.latest' => 900,
        'list' => 3600,
        'manga' => 86400,
        'feed' => 3600,
        'feed.library' => 900,
        'statistics' => 86400,
        'recommendations' => 604800,
        'at_home' => 600,
    ])->and(config('readex.defaults'))->toBe([
        'language' => 'en',
        'content_ratings' => ['safe', 'suggestive'],
        'image_quality' => 'data-saver',
    ])->and(config('readex.page_sizes.ids'))->toBeLessThanOrEqual(100);
});
