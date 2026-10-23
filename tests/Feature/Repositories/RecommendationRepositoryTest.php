<?php

use App\Models\Manga;
use App\Models\Recommendation;
use App\Repositories\RecommendationRepository;
use App\Repositories\SettingsRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\MangaDexFixtures;

const RECS_RTW = 'aafb046c-9de4-4c3d-990c-6d75b079c0b9';

beforeEach(function () {
    Carbon::setTestNow('2026-10-24 12:00:00');
    Sleep::fake(syncWithCarbon: true);
    $this->settings = app(SettingsRepository::class);
    $this->recs = app(RecommendationRepository::class);

    // The detail screen's manga is saved before its "More like this" row loads.
    Manga::query()->create([
        'id' => RECS_RTW, 'title' => ['zh-ro' => 'Fàngkāi Nàge Nǚwū'], 'alt_titles' => [['en' => 'Release That Witch']],
        'description' => [], 'content_rating' => 'safe', 'available_languages' => ['en', 'es-la'], 'tag_ids' => [],
        'fetched_at' => Carbon::now(),
    ]);
});

/** First 8 characters of each id. */
function recsIds(iterable $manga): array
{
    return collect($manga)->map(fn (Manga $m) => substr($m->id, 0, 8))->values()->all();
}

/** Http::fake() stubs stack and the fixture fake throws on anything unrecorded, so start over. */
function fakeRecsAgain(Closure $stub): void
{
    Http::swap(new Factory(app('events')));
    Http::fake($stub);
}

/** A recommendation answer: recommended id => score. */
function recsAnswer(array $scores): array
{
    $data = [];

    foreach ($scores as $id => $score) {
        $data[] = ['id' => RECS_RTW.'_'.$id, 'type' => 'manga_recommendation', 'attributes' => ['score' => $score], 'relationships' => [
            ['id' => RECS_RTW, 'type' => 'manga'],
            ['id' => $id, 'type' => 'manga'],
        ]];
    }

    return ['result' => 'ok', 'response' => 'collection', 'data' => $data, 'limit' => 50, 'offset' => 0, 'total' => count($data)];
}

it('loads recommendations plus one /manga call for covers, best score first', function () {
    MangaDexFixtures::fake('recommendations-rtw', 'recommendations-rtw-manga');

    $this->recs->refresh(RECS_RTW);
    $result = $this->recs->get(RECS_RTW);

    // English reader: 33 with English chapters by score, then the 6 without.
    expect($result->data)->toHaveCount(39)
        ->and(recsIds($result->data->take(5)))->toBe(['4026ddbd', 'af9aad67', 'd9e58461', '1e38f546', 'fe840243'])
        ->and(recsIds($result->data->take(-3)))->toBe(['0b0df25e', '14e99ffe', 'ed25bd9e'])
        ->and($result->data->first()->cover_file)->not->toBeNull()
        ->and($result->isStale)->toBeFalse()
        ->and(Recommendation::query()->where('manga_id', RECS_RTW)->count())->toBe(39);
    Http::assertSentCount(2);
});

it('puts Spanish series first for a Spanish reader, score order inside (D17)', function () {
    $this->settings->setLanguage('es-la');
    MangaDexFixtures::fake('recommendations-rtw', 'recommendations-rtw-manga');

    $this->recs->refresh(RECS_RTW);
    $data = $this->recs->get(RECS_RTW)->data;

    expect(recsIds($data->take(10)))->toBe(['fe840243', '9e74f69c', '438b7cbc', '44f84cc1', '16800ea3', 'ff9791c2', '1e940efb', 'e900135b', '39960d43', '4026ddbd'])
        ->and($this->settings->chain()->badge($data[8]->available_languages))->toBe('ES')
        ->and($this->settings->chain()->badge($data[9]->available_languages))->toBeNull();
});

it('filters by the content-rating setting at read time, without a refetch', function () {
    MangaDexFixtures::fake('recommendations-rtw', 'recommendations-rtw-manga');
    $this->recs->refresh(RECS_RTW);
    $top = $this->recs->get(RECS_RTW)->data->first()->id;
    Manga::query()->whereKey($top)->update(['content_rating' => 'erotica']);
    fakeRecsAgain(fn () => throw new RuntimeException('no API call expected'));

    expect($this->recs->get(RECS_RTW)->data)->toHaveCount(38)
        ->and($this->recs->get(RECS_RTW)->data->pluck('id'))->not->toContain($top);

    $this->settings->setContentRatings(['safe', 'suggestive', 'erotica']);

    expect($this->recs->get(RECS_RTW)->data)->toHaveCount(39)
        ->and($this->recs->get(RECS_RTW)->data->first()->id)->toBe($top);
});

it('asks /manga for every content rating', function () {
    MangaDexFixtures::fake('recommendations-rtw', 'recommendations-rtw-manga');

    $this->recs->refresh(RECS_RTW);

    Http::assertSent(fn (Request $r) => ! str_contains($r->url(), '/recommendation')
        && substr_count($r->url(), 'contentRating%5B%5D=') === 4);
});

it('keeps the saved row when the covers call fails (Review Focus 4)', function () {
    MangaDexFixtures::fake('recommendations-rtw', 'recommendations-rtw-manga');
    $this->recs->refresh(RECS_RTW);
    $before = recsIds($this->recs->get(RECS_RTW)->data);

    Carbon::setTestNow('2026-11-01 12:00:00');
    fakeRecsAgain(fn (Request $r) => str_contains($r->url(), '/recommendation')
        ? Http::response(recsAnswer(['00000000-0000-4000-8000-000000000001' => 0.9]))
        : throw new ConnectionException('offline'));

    expect(fn () => $this->recs->refresh(RECS_RTW))->toThrow(ConnectionException::class);
    $result = $this->recs->get(RECS_RTW);

    expect(recsIds($result->data))->toBe($before)
        ->and($result->isStale)->toBeTrue();
});

it('stays fresh for a series with no recommendations, and makes no /manga call', function () {
    fakeRecsAgain(fn () => Http::response(recsAnswer([])));

    expect($this->recs->get(RECS_RTW)->isStale)->toBeTrue();

    $this->recs->refresh(RECS_RTW);
    $result = $this->recs->get(RECS_RTW);

    expect($result->isEmpty())->toBeTrue()
        ->and($result->isStale)->toBeFalse();
    Http::assertSentCount(1);

    Carbon::setTestNow('2026-10-31 12:00:00');
    expect($this->recs->get(RECS_RTW)->isStale)->toBeTrue();
});

it('leaves out a recommended manga /manga did not return', function () {
    $known = MangaDexFixtures::body('recommendations-rtw-manga')['data'][0];
    fakeRecsAgain(fn (Request $r) => str_contains($r->url(), '/recommendation')
        ? Http::response(recsAnswer([$known['id'] => 0.8, '00000000-0000-4000-8000-000000000001' => 0.9]))
        : Http::response(['result' => 'ok', 'response' => 'collection', 'data' => [$known], 'limit' => 100, 'offset' => 0, 'total' => 1]));

    $this->recs->refresh(RECS_RTW);
    $result = $this->recs->get(RECS_RTW);

    expect($result->data->pluck('id')->all())->toBe([$known['id']])
        ->and($result->isStale)->toBeFalse();
});

it('is stale when a recommended manga row is gone', function () {
    MangaDexFixtures::fake('recommendations-rtw', 'recommendations-rtw-manga');
    $this->recs->refresh(RECS_RTW);
    $gone = $this->recs->get(RECS_RTW)->data->first()->id;

    Manga::query()->whereKey($gone)->delete();
    $result = $this->recs->get(RECS_RTW);

    expect($result->data)->toHaveCount(38)
        ->and($result->isStale)->toBeTrue();
});
