<?php

use App\Models\Collection;
use App\Models\Manga;
use App\Models\MangaListEntry;
use App\Models\Setting;
use App\Models\StoredChapter;
use App\Repositories\Freshness;
use App\Repositories\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-10-24 12:00:00');
    $this->settings = app(SettingsRepository::class);
});

function listRow(string $key, string $mangaId, int $position = 0): array
{
    return ['list_key' => $key, 'batch' => 1, 'position' => $position, 'manga_id' => $mangaId, 'fetched_at' => Carbon::now()];
}

it('answers with the defaults when nothing is saved', function () {
    expect($this->settings->language())->toBe('en')
        ->and($this->settings->chain()->languages)->toBe(['en'])
        ->and($this->settings->contentRatings())->toBe(['safe', 'suggestive'])
        ->and($this->settings->imageQuality())->toBe('data-saver')
        ->and($this->settings->setupDone())->toBeFalse()
        ->and($this->settings->lastDataRefreshAt())->toBeNull()
        ->and($this->settings->seasonalListId())->toBeNull()
        ->and($this->settings->lastSearch())->toBe(['query' => '', 'tag_id' => null]);
});

it('saves every setting and reads it back', function () {
    $this->settings->setLanguage('es-la');
    $this->settings->setContentRatings(['safe', 'suggestive', 'erotica']);
    $this->settings->setImageQuality('original');
    $this->settings->markSetupDone();
    $this->settings->markDataRefreshed();
    $this->settings->setSeasonalListId('list-1');
    $this->settings->rememberSearch('one punch', 'tag-1');

    $fresh = new SettingsRepository;

    expect($fresh->language())->toBe('es-la')
        ->and($fresh->chain()->languages)->toBe(['es-la', 'es', 'en'])
        ->and($fresh->contentRatings())->toBe(['safe', 'suggestive', 'erotica'])
        ->and($fresh->imageQuality())->toBe('original')
        ->and($fresh->setupDone())->toBeTrue()
        ->and($fresh->lastDataRefreshAt()->toDateTimeString())->toBe('2026-10-24 12:00:00')
        ->and($fresh->seasonalListId())->toBe('list-1')
        ->and($fresh->lastSearch())->toBe(['query' => 'one punch', 'tag_id' => 'tag-1']);
});

it('normalises the language code', function () {
    $this->settings->setLanguage(' ES-LA ');

    expect($this->settings->language())->toBe('es-la');
});

it('rejects a malformed language code', function (string $code) {
    expect(fn () => $this->settings->setLanguage($code))->toThrow(InvalidArgumentException::class)
        ->and(Setting::find('language'))->toBeNull();
})->with(['', 'spanish', 'es_la', 'e']);

it('marks only Latest updates stale when the language changes', function () {
    DB::table('manga_lists')->insert(listRow('home.latest', 'a1'));
    DB::table('manga_lists')->insert(listRow('home.season', 'a1'));
    DB::table('manga_lists')->insert(listRow('search:abc', 'a1'));

    $this->settings->setLanguage('es-la');

    $latest = MangaListEntry::where('list_key', 'home.latest')->first();

    expect($latest)->not->toBeNull()
        ->and($latest->fetched_at)->toBeNull()
        ->and(Freshness::isStale($latest->fetched_at, 'home.latest'))->toBeTrue()
        ->and(MangaListEntry::where('list_key', 'home.season')->first()->fetched_at)->not->toBeNull()
        ->and(MangaListEntry::where('list_key', 'search:abc')->first()->fetched_at)->not->toBeNull();
});

it('leaves Latest updates alone when the same language is saved again', function () {
    $this->settings->setLanguage('es-la');
    DB::table('manga_lists')->insert(listRow('home.latest', 'a1'));

    $this->settings->setLanguage('ES-LA');

    expect(MangaListEntry::where('list_key', 'home.latest')->first()->fetched_at)->not->toBeNull();
});

it('clears every saved list, and nothing else, when the content rating changes', function () {
    DB::table('manga_lists')->insert(listRow('home.season', 'a1'));
    DB::table('manga_lists')->insert(listRow('home.latest', 'a1'));
    DB::table('manga_lists')->insert(listRow('search:abc', 'a1'));
    DB::table('manga_lists')->insert(listRow('tag:t1', 'a1'));
    Manga::create(['id' => 'a1', 'title' => ['en' => 'A'], 'alt_titles' => [], 'description' => [], 'content_rating' => 'safe', 'available_languages' => ['en'], 'tag_ids' => []]);
    Collection::create(['manga_id' => 'a1']);
    StoredChapter::create(['chapter_id' => 'c1', 'tier' => 'local', 'quality' => 'data-saver', 'status' => 'done']);

    $this->settings->setContentRatings(['safe']);

    expect(DB::table('manga_lists')->count())->toBe(0)
        ->and(Manga::count())->toBe(1)
        ->and(Collection::count())->toBe(1)
        ->and(StoredChapter::count())->toBe(1)
        ->and($this->settings->contentRatings())->toBe(['safe']);
});

it('keeps saved lists when the same ratings come back in another order', function () {
    DB::table('manga_lists')->insert(listRow('home.season', 'a1'));

    $this->settings->setContentRatings(['suggestive', 'safe', 'safe']);

    expect(DB::table('manga_lists')->count())->toBe(1)
        ->and($this->settings->contentRatings())->toBe(['safe', 'suggestive']);
});

it('stores content ratings in MangaDex order', function () {
    $this->settings->setContentRatings(['pornographic', 'safe', 'erotica']);

    expect($this->settings->contentRatings())->toBe(['safe', 'erotica', 'pornographic']);
});

it('rejects unknown or empty content ratings and clears nothing', function (array $ratings) {
    DB::table('manga_lists')->insert(listRow('home.season', 'a1'));

    expect(fn () => $this->settings->setContentRatings($ratings))->toThrow(InvalidArgumentException::class)
        ->and(DB::table('manga_lists')->count())->toBe(1)
        ->and($this->settings->contentRatings())->toBe(['safe', 'suggestive']);
})->with([
    'empty' => [[]],
    'unknown' => [['safe', 'nsfw']],
]);

it('rejects an unknown image quality', function () {
    $this->settings->setImageQuality('hd');
})->throws(InvalidArgumentException::class);
