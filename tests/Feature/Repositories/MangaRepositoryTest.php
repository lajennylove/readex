<?php

use App\Models\Manga;
use App\Models\MangaListEntry;
use App\Repositories\MangaRepository;
use App\Repositories\SettingsRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\SourceFixtures;

const OPM = 'd8a959f7-648e-4c8d-8f23-f1f3f8e129f3';
const NOVA = '8a45c930-32d1-423e-bffd-9e8d71312134';
const COOKING = 'ea2bc92d-1c26-4930-9b7c-d5c0dc1b6869';

beforeEach(function () {
    Carbon::setTestNow('2026-10-24 12:00:00');
    Sleep::fake(syncWithCarbon: true);
    config(['readex.page_sizes.list' => 10]);
    $this->settings = app(SettingsRepository::class);
    $this->manga = app(MangaRepository::class);
});

/** First 8 characters of each id, enough to read the expected order. */
function shortIds(iterable $manga): array
{
    return collect($manga)->map(fn (Manga $m) => substr($m->id, 0, 8))->all();
}

/** Http::fake() stubs stack and the fixture fake throws on anything unrecorded, so start over. */
function fakeAgain(Closure $stub): void
{
    Http::swap(new Factory(app('events')));
    Http::fake($stub);
}

it('puts Spanish series first for a Spanish reader, API order inside each group (Review Focus 1)', function () {
    $this->settings->setLanguage('es-la');
    SourceFixtures::fake('search-one-punch-p1');

    expect($this->manga->refreshSearch('one punch'))->toBeTrue();
    $result = $this->manga->search('one punch');

    expect(shortIds($result->data))->toBe([
        'b7d069cb', 'd8a959f7', '29c42e49', 'ed9d3980', // es-la / es
        'a1c9cef0', '465a9169', 'cec6d978', 'fc102424', 'c1ac2b39', // English
        '2053e994', // no chapters at all
    ])
        ->and($result->isStale)->toBeFalse()
        ->and($this->settings->chain()->badge($result->data[0]->available_languages))->toBe('ES')
        ->and($this->settings->chain()->badge($result->data[4]->available_languages))->toBeNull();
});

it('keeps the API order for an English reader, series without chapters last', function () {
    SourceFixtures::fake('search-one-punch-p1');

    $this->manga->refreshSearch('one punch');

    expect(shortIds($this->manga->search('one punch')->data))->toBe([
        'a1c9cef0', 'b7d069cb', 'd8a959f7', '29c42e49', '465a9169', 'cec6d978', 'fc102424', 'ed9d3980', 'c1ac2b39', '2053e994',
    ]);
});

it('appends the next page as its own batch, earlier tiles keep their place (Review Focus 2)', function () {
    $this->settings->setLanguage('es-la');
    SourceFixtures::fake('search-one-punch-p1', 'search-one-punch-p2');

    $this->manga->refreshSearch('one punch');
    $before = shortIds($this->manga->search('one punch')->data);

    expect($this->manga->loadMore('one punch'))->toBeFalse();
    $after = shortIds($this->manga->search('one punch')->data);

    expect($after)->toHaveCount(18)
        ->and(array_slice($after, 0, 10))->toBe($before)
        ->and(array_slice($after, 10))->toBe([
            '9147871d',
            '5aadd902', '24657ac0', '6431a544', '7e52445d', '6259f21b', '5b6853e2',
            'f0af90c5',
        ]);
});

it('re-sorts on a language change without any API call', function () {
    SourceFixtures::fake('search-one-punch-p1');
    $this->manga->refreshSearch('one punch');
    fakeAgain(fn () => throw new RuntimeException('no network expected'));

    $this->settings->setLanguage('es-la');

    expect(shortIds($this->manga->search('one punch')->data)[0])->toBe('b7d069cb');
});

it('keeps the saved list when offline, marked stale (Review Focus 4)', function () {
    SourceFixtures::fake('search-one-punch-p1');
    $this->manga->refreshSearch('one punch');

    Carbon::setTestNow(Carbon::now()->addHour());
    fakeAgain(fn () => throw new ConnectionException('offline'));

    expect(fn () => $this->manga->refreshSearch('one punch'))->toThrow(ConnectionException::class)
        ->and(fn () => $this->manga->loadMore('one punch'))->toThrow(ConnectionException::class);

    $result = $this->manga->search('one punch');
    expect($result->data)->toHaveCount(10)
        ->and($result->isStale)->toBeTrue()
        ->and($result->fetchedAt->toDateTimeString())->toBe('2026-10-24 12:00:00');
});

it('replaces the saved list on refresh', function () {
    $key = $this->manga->listKey('one punch');
    MangaListEntry::insert(['list_key' => $key, 'batch' => 1, 'position' => 0, 'manga_id' => NOVA, 'fetched_at' => Carbon::now()]);
    SourceFixtures::fake('search-one-punch-p1');

    $this->manga->refreshSearch('one punch');

    expect(MangaListEntry::where('list_key', $key)->pluck('manga_id'))->not->toContain(NOVA)
        ->and(MangaListEntry::where('list_key', $key)->count())->toBe(10);
});

it('skips a series repeated on the next page, keeping its first place', function () {
    SourceFixtures::fake('search-one-punch-p1');
    $this->manga->refreshSearch('one punch');

    $page2 = SourceFixtures::body('search-one-punch-p2');
    $page2['data'][0] = SourceFixtures::body('search-one-punch-p1')['data'][2];
    fakeAgain(fn () => Http::response($page2));

    $this->manga->loadMore('one punch');

    $key = $this->manga->listKey('one punch');
    expect(MangaListEntry::where('list_key', $key)->count())->toBe(17)
        ->and(MangaListEntry::where('list_key', $key)->where('manga_id', OPM)->value('position'))->toBe(2);
});

it('browses a tag by most followed', function () {
    SourceFixtures::fake('browse-cooking');

    expect($this->manga->refreshSearch('', [COOKING]))->toBeTrue()
        ->and($this->manga->search('', [COOKING])->data)->toHaveCount(10);
});

it('builds one list key per query, tags and content ratings, never per language', function () {
    $key = $this->manga->listKey('One  Punch ', ['b', 'a']);

    expect($key)->toBe($this->manga->listKey('one punch', ['a', 'b']))
        ->and($key)->toStartWith('search:')
        ->and($key)->not->toBe($this->manga->listKey('one punch', ['a']));

    $this->settings->setLanguage('es-la');
    expect($this->manga->listKey('one punch', ['a', 'b']))->toBe($key);

    $this->settings->setContentRatings(['safe']);
    expect($this->manga->listKey('one punch', ['a', 'b']))->not->toBe($key);
});

it('stops at the 10,000 result cap without calling the API', function () {
    MangaListEntry::insert(['list_key' => $this->manga->listKey('x'), 'batch' => 1, 'position' => 9_999, 'manga_id' => OPM, 'fetched_at' => Carbon::now()]);
    fakeAgain(fn () => throw new RuntimeException('no network expected'));

    expect($this->manga->loadMore('x'))->toBeFalse();
});

it('fetches manga by ids and answers in the order asked', function () {
    SourceFixtures::fake('manga-ids');

    $this->manga->refreshByIds([OPM, NOVA]);
    $result = $this->manga->byIds([NOVA, OPM]);

    expect($result->data->pluck('id')->all())->toBe([NOVA, OPM])
        ->and($result->isStale)->toBeFalse()
        ->and($result->data[1]->cover_file)->not->toBeNull();

    $missing = $this->manga->byIds([OPM, '00000000-0000-0000-0000-000000000000']);
    expect($missing->data->pluck('id')->all())->toBe([OPM])
        ->and($missing->isStale)->toBeTrue();
});

it('asks for at most 100 ids per call', function () {
    Http::fake(fn () => Http::response(['result' => 'ok', 'data' => [], 'total' => 0]));
    $ids = array_map(fn (int $i) => sprintf('00000000-0000-0000-0000-%012d', $i), range(1, 150));

    $this->manga->refreshByIds([...$ids, $ids[0]]);

    $sizes = collect(Http::recorded())->map(fn (array $pair) => substr_count(urldecode($pair[0]->url()), 'ids[]='))->all();
    expect($sizes)->toBe([100, 50]);
    Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'contentRating[]=pornographic'));
});

it('saves a series detail and marks it stale after a day', function () {
    SourceFixtures::fake('manga-nova');

    $this->manga->refreshDetail(NOVA);

    expect($this->manga->detail(NOVA)->data->content_rating)->toBeString()
        ->and($this->manga->detail(NOVA)->isStale)->toBeFalse()
        ->and($this->manga->detail('00000000-0000-0000-0000-000000000000')->isEmpty())->toBeTrue();

    Carbon::setTestNow(Carbon::now()->addDay());
    expect($this->manga->detail(NOVA)->isStale)->toBeTrue();
});

it('saves author and artist with the detail, and a later list call keeps them', function () {
    SourceFixtures::fake('manga-nova', 'manga-ids');

    $this->manga->refreshDetail(NOVA);
    $this->manga->refreshByIds([OPM, NOVA]);

    $nova = $this->manga->detail(NOVA)->data;

    expect($nova->authors)->toBe(['Clevercool'])
        ->and($nova->artists)->toBe(['Clevercool'])
        ->and(Manga::find(OPM)->authors)->toBeNull();
});

it('prunes search lists nobody refreshed for a week, other lists stay', function () {
    $row = fn (string $key, string $at) => ['list_key' => $key, 'batch' => 1, 'position' => 0, 'manga_id' => OPM, 'fetched_at' => $at];
    MangaListEntry::insert([
        $row('search:old', '2026-10-17 11:59:59'),
        $row('search:recent', '2026-10-17 12:00:01'),
        $row('home.popular', '2026-10-01 00:00:00'),
    ]);

    expect($this->manga->pruneLists())->toBe(1)
        ->and(MangaListEntry::pluck('list_key')->sort()->values()->all())->toBe(['home.popular', 'search:recent']);
});
