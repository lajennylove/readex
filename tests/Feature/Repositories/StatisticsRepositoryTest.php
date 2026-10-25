<?php

use App\Models\Statistic;
use App\Repositories\StatisticsRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\SourceFixtures;

const STATS_RTW = 'aafb046c-9de4-4c3d-990c-6d75b079c0b9';

beforeEach(function () {
    Carbon::setTestNow('2026-10-24 12:00:00');
    Sleep::fake(syncWithCarbon: true);
    $this->stats = app(StatisticsRepository::class);
});

/** The 39 manga recommended for Release that Witch (ids of the recorded batch). */
function statsRecommendedIds(): array
{
    return array_keys(SourceFixtures::body('statistics-rtw-recommended')['statistics']);
}

/** Http::fake() stubs stack and the fixture fake throws on anything unrecorded, so start over. */
function fakeStatsAgain(Closure $stub): void
{
    Http::swap(new Factory(app('events')));
    Http::fake($stub);
}

/** Ids of `manga[]=` in the request URL. */
function statsRequestedIds(Request $request): array
{
    preg_match_all('/manga%5B%5D=([0-9a-f-]+)/', $request->url(), $m);

    return $m[1];
}

/** A `/statistics/manga` answer with the same numbers for every id asked. */
function statsAnswer(array $ids, ?float $average = 7.5, int $follows = 10): array
{
    return ['result' => 'ok', 'statistics' => array_fill_keys($ids, [
        'comments' => null,
        'follows' => $follows,
        'rating' => ['average' => $average, 'bayesian' => $average ?? 0],
        'unavailableChaptersCount' => 0,
    ])];
}

it('saves ratings and follows for a batch of manga from one call', function () {
    SourceFixtures::fake('statistics-rtw-recommended');
    $ids = statsRecommendedIds();

    $this->stats->refresh($ids);
    $result = $this->stats->forManga($ids);
    $first = $result->data[$ids[0]];

    expect($result->data)->toHaveCount(39)
        ->and($result->isStale)->toBeFalse()
        ->and($first->follows)->toBe(SourceFixtures::body('statistics-rtw-recommended')['statistics'][$ids[0]]['follows'])
        ->and($first->rating_bayesian)->toBeFloat()
        ->and($first->distribution)->toBeNull();
    Http::assertSentCount(1);
});

it('sends at most page_sizes.ids manga per call', function () {
    $ids = array_map(fn (int $i) => sprintf('00000000-0000-4000-8000-%012d', $i), range(1, 150));
    $sizes = [];
    fakeStatsAgain(function (Request $request) use (&$sizes) {
        $sizes[] = count(statsRequestedIds($request));

        return Http::response(statsAnswer(statsRequestedIds($request)));
    });

    $this->stats->refresh([...$ids, $ids[0]]);

    expect($sizes)->toBe([100, 50])
        ->and(Statistic::query()->count())->toBe(150);
});

it('keeps the saved statistics when a later batch fails (Review Focus 4)', function () {
    $ids = array_map(fn (int $i) => sprintf('00000000-0000-4000-8000-%012d', $i), range(1, 150));
    fakeStatsAgain(fn (Request $request) => Http::response(statsAnswer(statsRequestedIds($request), 6.0, 1)));
    $this->stats->refresh([$ids[0]]);

    Carbon::setTestNow('2026-10-25 13:00:00');
    $calls = 0;
    fakeStatsAgain(function (Request $request) use (&$calls) {
        return ++$calls === 1 ? Http::response(statsAnswer(statsRequestedIds($request), 9.0, 99)) : throw new ConnectionException('offline');
    });

    expect(fn () => $this->stats->refresh($ids))->toThrow(ConnectionException::class);
    $result = $this->stats->forManga([$ids[0]]);

    expect(Statistic::query()->count())->toBe(1)
        ->and($result->data[$ids[0]]->follows)->toBe(1)
        ->and($result->isStale)->toBeTrue();
});

it('is stale when an id has no saved statistics, or after a day', function () {
    fakeStatsAgain(fn (Request $request) => Http::response(statsAnswer(statsRequestedIds($request))));
    $ids = ['00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002'];
    $this->stats->refresh([$ids[0]]);

    expect($this->stats->forManga([$ids[0]])->isStale)->toBeFalse()
        ->and($this->stats->forManga($ids)->isStale)->toBeTrue()
        ->and($this->stats->forManga($ids)->data)->toHaveCount(1);

    Carbon::setTestNow('2026-10-25 12:00:00');
    expect($this->stats->forManga([$ids[0]])->isStale)->toBeTrue();
});

it('saves a null rating for a series nobody rated yet', function () {
    fakeStatsAgain(fn (Request $request) => Http::response(statsAnswer(statsRequestedIds($request), null, 0)));
    $id = '00000000-0000-4000-8000-000000000001';

    $this->stats->refresh([$id]);
    $statistic = $this->stats->forManga([$id])->data[$id];

    expect($statistic->rating_avg)->toBeNull()
        ->and($statistic->follows)->toBe(0);
});

it('loads the rating distribution for the detail screen', function () {
    SourceFixtures::fake('statistics-rtw');

    expect($this->stats->detail(STATS_RTW)->isEmpty())->toBeTrue();

    $this->stats->refreshDetail(STATS_RTW);
    $result = $this->stats->detail(STATS_RTW);

    expect($result->isStale)->toBeFalse()
        ->and($result->data->follows)->toBe(40308)
        ->and($result->data->rating_avg)->toBe(8.3233)
        ->and($result->data->distribution)->toBe([1 => 107, 2 => 14, 3 => 39, 4 => 118, 5 => 85, 6 => 131, 7 => 301, 8 => 601, 9 => 765, 10 => 1343]);
});

it('marks the detail stale while only batched numbers are saved, and a batch keeps the distribution', function () {
    fakeStatsAgain(fn (Request $request) => Http::response(statsAnswer(statsRequestedIds($request), 8.0, 50_000)));
    $this->stats->refresh([STATS_RTW]);

    expect($this->stats->detail(STATS_RTW)->isStale)->toBeTrue();

    fakeStatsAgain(SourceFixtures::stub('statistics-rtw'));
    $this->stats->refreshDetail(STATS_RTW);

    Carbon::setTestNow('2026-10-24 12:00:10');
    fakeStatsAgain(fn (Request $request) => Http::response(statsAnswer(statsRequestedIds($request), 8.0, 50_000)));
    $this->stats->refresh([STATS_RTW]);
    $result = $this->stats->detail(STATS_RTW);

    expect($result->isStale)->toBeFalse()
        ->and($result->data->follows)->toBe(50_000)
        ->and($result->data->distribution[10])->toBe(1343);
});
