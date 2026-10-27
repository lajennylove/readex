<?php

use App\Adapters\Http\Exceptions\ApiError;
use App\Models\Chapter;
use App\Models\Manga;
use App\Models\MangaListEntry;
use App\Models\ReadingProgress;
use App\Models\StoredChapter;
use App\Repositories\ChapterRepository;
use App\Repositories\HomeRepository;
use App\Repositories\LatestUpdate;
use App\Repositories\SettingsRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\SourceFixtures;

const SEASON_LIST = '68ab4f4e-6f01-4898-9038-c5eee066be27';
const SEASONAL_HOST = 'example.github.io';
const SEASONAL_URL = 'https://example.github.io/seasonal-list.json';

beforeEach(function () {
    Carbon::setTestNow('2026-10-24 12:00:00');
    Sleep::fake(syncWithCarbon: true);
    $this->settings = app(SettingsRepository::class);
    // Opt the row in for the whole file (Plan-10 seasonalUrl gate) so these tests
    // keep exercising the fetch path; a dedicated test below covers the "unset -> hidden" path.
    $this->settings->setSeasonalUrl(SEASONAL_URL);
    $this->home = app(HomeRepository::class);
});

/** First 8 characters of each id. */
function homeIds(iterable $manga): array
{
    return collect($manga)->map(fn (Manga $m) => substr($m->id, 0, 8))->values()->all();
}

/** Http::fake() stubs stack and the fixture fake throws on anything unrecorded, so start over. */
function fakeHomeAgain(Closure $stub): void
{
    Http::swap(new Factory(app('events')));
    Http::fake($stub);
}

/** Fixtures for everything except the GitHub JSON, which answers with `$github`. */
function fakeSeasonalDown(Closure $github): void
{
    fakeHomeAgain(function (Request $request) use ($github) {
        $fixtures = SourceFixtures::stub('seasonal-list', 'season-manga');

        return parse_url($request->url(), PHP_URL_HOST) === SEASONAL_HOST ? $github($request) : $fixtures($request);
    });
}

/** Ids of `/manga?ids[]=` in the request URL. */
function requestedIds(Request $request): array
{
    preg_match_all('/ids%5B%5D=([0-9a-f-]+)/', $request->url(), $m);

    return $m[1];
}

it('loads This season from the JSON, remembers its list id, Spanish series first (Review Focus 1)', function () {
    $this->settings->setLanguage('es-la');
    SourceFixtures::fake('seasonal-json', 'season-manga');

    $this->home->refreshSeason();
    $result = $this->home->season();

    // 70 ids in the JSON, 5 are erotica/pornographic and the setting is safe + suggestive.
    expect($result->data)->toHaveCount(65)
        ->and(homeIds($result->data->take(8)))->toBe(['e18fe8c6', '77fd8118', '6a468761', 'fef2e4da', '80422e14', 'e7eabe96', '9d3d594f', '3fc308c9'])
        ->and(homeIds($result->data->take(-3)))->toBe(['b9c11202', 'd8beeb52', 'a3f6923a'])
        ->and($result->isStale)->toBeFalse()
        ->and($this->settings->seasonalListId())->toBe(SEASON_LIST);
});

it('uses the remembered the source list when the JSON is down (Review Focus 6)', function (Closure $github) {
    $this->settings->setSeasonalListId(SEASON_LIST);
    fakeSeasonalDown($github);

    $this->home->refreshSeason();

    expect($this->home->season()->data)->toHaveCount(65);
    Http::assertSent(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/list/'.SEASON_LIST));
})->with([
    'HTTP 404' => fn () => fn () => Http::response('Not Found', 404),
    'no connection' => fn () => fn () => throw new ConnectionException('offline'),
    'not JSON' => fn () => fn () => Http::response('<html>maintenance</html>', 200),
    'JSON without ids' => fn () => fn () => Http::response(['id' => SEASON_LIST], 200),
]);

it('keeps the row empty without a remembered list id, no hardcoded fallback (Review Focus 6)', function () {
    fakeSeasonalDown(fn () => Http::response('Not Found', 404));

    expect(fn () => $this->home->refreshSeason())->toThrow(ApiError::class)
        ->and($this->home->season()->isEmpty())->toBeTrue()
        ->and(Manga::query()->count())->toBe(0);
    Http::assertSentCount(1);
});

it('asks for the season in batches of at most page_sizes.ids', function () {
    config(['readex.page_sizes.ids' => 50]);
    $season = SourceFixtures::body('season-manga')['data'];
    $calls = [];

    fakeHomeAgain(function (Request $request) use ($season, &$calls) {
        if (parse_url($request->url(), PHP_URL_HOST) === SEASONAL_HOST) {
            return Http::response(SourceFixtures::body('seasonal-json'));
        }

        $calls[] = $ids = requestedIds($request);
        $data = array_values(array_filter($season, fn (array $m) => in_array($m['id'], $ids, true)));

        return Http::response(['result' => 'ok', 'data' => $data, 'total' => count($data)]);
    });

    $this->home->refreshSeason();

    expect(array_map(count(...), $calls))->toBe([50, 20])
        ->and($this->home->season()->data)->toHaveCount(65);
});

it('keeps the saved season when the manga call fails, stale after a day (Review Focus 4)', function () {
    SourceFixtures::fake('seasonal-json', 'season-manga');
    $this->home->refreshSeason();

    Carbon::setTestNow('2026-10-25 12:00:00');
    fakeHomeAgain(fn (Request $r) => parse_url($r->url(), PHP_URL_HOST) === SEASONAL_HOST
        ? Http::response(SourceFixtures::body('seasonal-json'))
        : throw new ConnectionException('offline'));

    expect(fn () => $this->home->refreshSeason())->toThrow(ConnectionException::class);
    $result = $this->home->season();

    expect($result->data)->toHaveCount(65)
        ->and($result->isStale)->toBeTrue();
});

it('loads Popular new titles created in the last 30 days, reordered for the reader', function () {
    $this->settings->setLanguage('es-la');
    SourceFixtures::fake('popular-new');

    $this->home->refreshPopular();
    $result = $this->home->popular();

    expect($result->data)->toHaveCount(20)
        ->and(homeIds($result->data->take(8)))->toBe(['cfef125c', '93704d83', 'af6667a4', 'b61247ba', '99e912e1', '26d2e5bb', '75f8931e', '7d425c5c'])
        ->and($result->isStale)->toBeFalse();

    Carbon::setTestNow('2026-10-24 18:00:00');
    expect($this->home->popular()->isStale)->toBeTrue();
});

it('keeps the API order for an English reader', function () {
    SourceFixtures::fake('popular-new');

    $this->home->refreshPopular();

    expect(homeIds($this->home->popular()->data->take(5)))->toBe(['cfef125c', '7d425c5c', '93704d83', 'af6667a4', 'b61247ba']);
});

it('shows one Latest updates tile per series, newest first, in two calls', function () {
    $this->settings->setLanguage('es-la');
    SourceFixtures::fake('latest-es-la', 'latest-es-la-manga');

    $this->home->refreshLatest();
    $result = $this->home->latest();
    $first = $result->data->first();

    $released = $result->data->map(fn (LatestUpdate $u) => $u->chapter->readable_at->getTimestamp())->all();
    $byRelease = $released;
    rsort($byRelease);

    // Not reordered by language: tiles 5, 6, 9, 10 have no Spanish and stay where they were released.
    expect($released)->toBe($byRelease)
        ->and($result->data)->toHaveCount(20)
        ->and($result->data->map(fn (LatestUpdate $u) => substr($u->manga->id, 0, 8))->take(4)->all())->toBe(['cfff2b2a', '6ad54367', '38179337', 'c9a8ffb7'])
        ->and($result->data->last()->manga->id)->toStartWith('0a62cb38')
        ->and($first->chapter->id)->toBe('91966e76-fbae-42e2-9c3b-f658fc6574ff')
        ->and($first->chapter->only(['language', 'number', 'group_name']))->toBe(['language' => 'es-la', 'number' => '21', 'group_name' => 'Hermit No Fansub'])
        ->and($result->data->every(fn (LatestUpdate $u) => $u->manga->cover_file !== null))->toBeTrue()
        ->and($result->isStale)->toBeFalse();
    Http::assertSentCount(2);

    Carbon::setTestNow('2026-10-24 12:15:00');
    expect($this->home->latest()->isStale)->toBeTrue();
});

it('never turns a Latest chapter into a one-chapter feed', function () {
    $this->settings->setLanguage('es-la');
    SourceFixtures::fake('latest-es-la', 'latest-es-la-manga');
    [$newest, $second] = SourceFixtures::body('latest-es-la')['data'];
    // The second tile's chapter was already loaded by its series' feed an hour ago.
    Chapter::create(['id' => $second['id'], 'manga_id' => 'old', 'language' => 'en', 'number' => '414', 'fetched_at' => Carbon::now()->subHour()]);

    $this->home->refreshLatest();
    $manga = Chapter::find($newest['id'])->manga_id;

    expect(app(ChapterRepository::class)->get($manga, 'es-la')->isEmpty())->toBeTrue()
        ->and(Chapter::find($second['id'])->fetched_at)->not->toBeNull()
        ->and(Chapter::find($second['id'])->number)->toBe('415');
});

it('forgets chapters only the old Latest row used, keeps stored and in-progress ones', function () {
    $this->settings->setLanguage('es-la');
    SourceFixtures::fake('latest-es-la', 'latest-es-la-manga');
    $this->home->refreshLatest();

    $old = MangaListEntry::query()->where('list_key', HomeRepository::LATEST)->orderBy('position')->pluck('chapter_id');
    StoredChapter::create(['chapter_id' => $old[1], 'tier' => 'cache', 'quality' => 'data-saver', 'status' => 'done', 'pages_done' => 1, 'page_count' => 1, 'bytes' => 1]);
    ReadingProgress::create(['chapter_id' => $old[2], 'manga_id' => 'x', 'page' => 1, 'page_count' => 10]);

    // Next refresh: the first three tiles' chapters are gone from the response.
    $chapters = SourceFixtures::body('latest-es-la');
    $chapters['data'] = array_values(array_filter($chapters['data'], fn (array $c) => ! in_array($c['id'], [$old[0], $old[1], $old[2]], true)));
    $manga = SourceFixtures::body('latest-es-la-manga');

    Carbon::setTestNow('2026-10-24 12:20:00');
    fakeHomeAgain(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/chapter') ? Http::response($chapters) : Http::response($manga));
    $this->home->refreshLatest();

    expect(Chapter::find($old[0]))->toBeNull()
        ->and(Chapter::find($old[1]))->not->toBeNull()
        ->and(Chapter::find($old[2]))->not->toBeNull()
        ->and($this->home->latest()->data->pluck('chapter.id'))->not->toContain($old[0]);
});

it('keeps the saved Latest tiles when the cover call fails (Review Focus 4)', function () {
    $this->settings->setLanguage('es-la');
    SourceFixtures::fake('latest-es-la', 'latest-es-la-manga');
    $this->home->refreshLatest();

    Carbon::setTestNow('2026-10-24 12:30:00');
    $chapters = SourceFixtures::body('latest-es-la');
    fakeHomeAgain(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/chapter') ? Http::response($chapters) : throw new ConnectionException('offline'));

    expect(fn () => $this->home->refreshLatest())->toThrow(ConnectionException::class);
    $result = $this->home->latest();

    expect($result->data)->toHaveCount(20)
        ->and($result->isStale)->toBeTrue();
});

it('goes stale when the reading language changes (choice 5)', function () {
    $this->settings->setLanguage('es-la');
    SourceFixtures::fake('latest-es-la', 'latest-es-la-manga');
    $this->home->refreshLatest();

    $this->settings->setLanguage('fr');

    expect($this->home->latest()->isStale)->toBeTrue()
        ->and($this->home->latest()->data)->toHaveCount(20);
});

it('sends the reader\'s chain and stores nothing for an empty answer', function () {
    $this->settings->setLanguage('pt-br');
    fakeHomeAgain(fn () => Http::response(['result' => 'ok', 'data' => [], 'total' => 0]));

    $this->home->refreshLatest();

    Http::assertSent(fn (Request $r) => str_contains(rawurldecode($r->url()), 'translatedLanguage[]=pt-br&translatedLanguage[]=pt&translatedLanguage[]=en'));
    Http::assertSentCount(1);
    expect($this->home->latest()->isEmpty())->toBeTrue();
});
