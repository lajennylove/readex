<?php

use App\Models\Chapter;
use App\Models\ChapterFeed;
use App\Models\Collection as LibraryEntry;
use App\Models\Manga;
use App\Models\ReadingProgress;
use App\Models\Setting;
use App\Models\StoredChapter;
use App\Repositories\ChapterRepository;
use App\Repositories\DataReset;
use App\Repositories\LibraryRepository;
use App\Repositories\MangaRepository;
use App\Repositories\RecommendationRepository;
use App\Repositories\SettingsRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

const RESET_LIBRARY = '11111111-0000-4000-8000-000000000000';
const RESET_STORED = '22222222-0000-4000-8000-000000000000';
const RESET_STARTED = '33333333-0000-4000-8000-000000000000';
const RESET_OTHER = '44444444-0000-4000-8000-000000000000';

beforeEach(function () {
    Carbon::setTestNow('2026-10-24 12:00:00');

    foreach ([RESET_LIBRARY, RESET_STORED, RESET_STARTED, RESET_OTHER] as $i => $id) {
        Manga::query()->create([
            'id' => $id, 'title' => ['en' => "Series {$i}"], 'alt_titles' => [], 'description' => [], 'content_rating' => 'safe',
            'available_languages' => ['en'], 'tag_ids' => [], 'fetched_at' => Carbon::now(), 'recommendations_fetched_at' => Carbon::now(),
        ]);
    }

    // Two chapters per series: c{series}{1|2}.
    foreach ([RESET_LIBRARY, RESET_STORED, RESET_STARTED, RESET_OTHER] as $i => $mangaId) {
        foreach ([1, 2] as $n) {
            Chapter::query()->create([
                'id' => resetChapter($i, $n), 'manga_id' => $mangaId, 'number' => (string) $n, 'language' => 'en',
                'group_id' => 'aaaaaaaa-0000-4000-8000-000000000000', 'group_name' => 'Group A', 'fetched_at' => Carbon::now(),
            ]);
        }
        ChapterFeed::query()->create(['manga_id' => $mangaId, 'language' => 'en', 'total' => 2, 'fetched_at' => Carbon::now()]);
    }

    $library = app(LibraryRepository::class);
    $library->setFavorite(RESET_LIBRARY, true);
    $library->saveProgress(resetChapter(0, 1), 5, 20);
    StoredChapter::query()->insert(['chapter_id' => resetChapter(1, 1), 'tier' => 'local', 'quality' => 'data-saver', 'status' => 'done']);
    // A started chapter whose series isn't in the Library (any more).
    ReadingProgress::query()->create(['chapter_id' => resetChapter(2, 2), 'manga_id' => RESET_STARTED, 'page' => 1, 'page_count' => 20, 'updated_at' => Carbon::now()]);

    $settings = app(SettingsRepository::class);
    $settings->setLanguage('es-la');
    $settings->setSeasonalListId('5a7e1b32-0000-4000-8000-000000000000');
    $settings->markSetupDone();

    DB::table('tags')->insert(['id' => 'eeeeeeee-0000-4000-8000-000000000000', 'group' => 'genre', 'name' => '{"en":"Action"}', 'fetched_at' => Carbon::now()]);
    DB::table('manga_lists')->insert([
        ['list_key' => 'home.season', 'batch' => 0, 'position' => 0, 'manga_id' => RESET_LIBRARY, 'fetched_at' => Carbon::now()],
        ['list_key' => 'search:abc', 'batch' => 0, 'position' => 0, 'manga_id' => RESET_OTHER, 'fetched_at' => Carbon::now()],
    ]);
    DB::table('statistics')->insert(['manga_id' => RESET_LIBRARY, 'follows' => 10, 'fetched_at' => Carbon::now()]);
    DB::table('recommendations')->insert(['manga_id' => RESET_LIBRARY, 'recommended_id' => RESET_OTHER, 'position' => 0, 'score' => 0.9, 'fetched_at' => Carbon::now()]);
    DB::table('at_home')->insert(['chapter_id' => resetChapter(0, 1), 'base_url' => 'https://uploads.source.test', 'hash' => 'h', 'data_files' => '[]', 'data_saver_files' => '[]', 'fetched_at' => Carbon::now()]);
});

function resetChapter(int $series, int $n): string
{
    return "c{$series}{$n}00000-0000-4000-8000-000000000000";
}

/** Every row of the user tables, to compare before and after. */
function resetUserRows(): array
{
    return collect(['settings', 'collections', 'reading_progress', 'stored_chapters'])
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->get()->map(fn ($row) => (array) $row)->sortBy(fn ($r) => json_encode($r))->values()->all()])
        ->all();
}

it('clears the source data and keeps the Library, marked stale (Review Focus 5)', function () {
    $before = resetUserRows();
    Carbon::setTestNow('2026-10-24 12:05:00');

    app(DataReset::class)->clearRemoteData();

    foreach (['manga_lists', 'tags', 'statistics', 'recommendations', 'at_home', 'chapter_feeds'] as $table) {
        expect(DB::table($table)->count())->toBe(0, $table);
    }

    // Library series keeps all its chapters; others keep only stored / started chapters.
    expect(Manga::query()->orderBy('id')->pluck('id')->all())->toBe([RESET_LIBRARY, RESET_STORED, RESET_STARTED])
        ->and(Chapter::query()->orderBy('id')->pluck('id')->all())->toBe([resetChapter(0, 1), resetChapter(0, 2), resetChapter(1, 1), resetChapter(2, 2)])
        ->and(Manga::query()->whereNotNull('fetched_at')->orWhereNotNull('recommendations_fetched_at')->count())->toBe(0)
        ->and(Chapter::query()->whereNotNull('fetched_at')->count())->toBe(0)
        ->and(resetUserRows())->toBe($before);
});

it('leaves the Library usable offline and every remote read stale', function () {
    app(DataReset::class)->clearRemoteData();

    $sections = app(LibraryRepository::class)->sections(RESET_LIBRARY, 'en');
    $feed = app(ChapterRepository::class)->get(RESET_LIBRARY, 'en');

    expect($sections[0]['chapters']->map(fn ($c) => $c->chapter->number)->all())->toBe(['1', '2'])
        ->and($sections[0]['chapters'][0]->progress->page)->toBe(5)
        ->and(app(LibraryRepository::class)->continueReading())->toHaveCount(2)
        ->and($feed->isEmpty())->toBeTrue()->and($feed->isStale)->toBeTrue()
        ->and(app(MangaRepository::class)->detail(RESET_LIBRARY)->isStale)->toBeTrue()
        ->and(app(RecommendationRepository::class)->get(RESET_LIBRARY)->isStale)->toBeTrue()
        ->and(app(SettingsRepository::class)->seasonalListId())->toBe('5a7e1b32-0000-4000-8000-000000000000')
        ->and(LibraryEntry::query()->find(RESET_LIBRARY)->favorite)->toBeTrue()
        ->and(Setting::query()->count())->toBe(3);
});

it('changes nothing when the transaction fails', function () {
    DB::statement('CREATE TRIGGER reset_fails BEFORE DELETE ON manga BEGIN SELECT RAISE(ABORT, \'boom\'); END');

    expect(fn () => app(DataReset::class)->clearRemoteData())->toThrow(QueryException::class);

    expect(DB::table('manga_lists')->count())->toBe(2)
        ->and(Chapter::query()->count())->toBe(8)
        ->and(Chapter::query()->whereNull('fetched_at')->count())->toBe(0);
});
