<?php

use App\Repositories\Freshness;
use App\Repositories\Result;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

beforeEach(fn () => Carbon::setTestNow('2026-10-24 12:00:00'));

it('turns stale at exactly the refresh age', function (string $kind, int $age) {
    $fetched = CarbonImmutable::parse('2026-10-24 12:00:00')->subSeconds($age);

    expect(Freshness::isStale($fetched->addSecond(), $kind))->toBeFalse()
        ->and(Freshness::isStale($fetched, $kind))->toBeTrue();
})->with([
    'latest updates, dotted key' => ['home.latest', 900],
    'library feed, dotted key' => ['feed.library', 900],
    'tags' => ['tags', 30 * 86400],
    'search list' => ['list', 3600],
]);

it('treats never fetched as stale', function () {
    expect(Freshness::isStale(null, 'manga'))->toBeTrue();
});

it('treats a fetch from the future as fresh', function () {
    expect(Freshness::isStale(CarbonImmutable::parse('2026-10-24 13:00:00'), 'manga'))->toBeFalse();
});

it('rejects an unknown kind', function () {
    Freshness::isStale(null, 'home');
})->throws(InvalidArgumentException::class, 'home');

it('builds a result with the stale flag', function () {
    $result = Freshness::result(collect(['a1']), CarbonImmutable::parse('2026-10-24 11:30:00'), 'list');

    expect($result)->toBeInstanceOf(Result::class)
        ->and($result->data->all())->toBe(['a1'])
        ->and($result->fetchedAt->toDateTimeString())->toBe('2026-10-24 11:30:00')
        ->and($result->isStale)->toBeFalse()
        ->and($result->isEmpty())->toBeFalse();
});

it('accepts mutable Carbon from Eloquent', function () {
    $result = Freshness::result(null, Carbon::parse('2026-10-24 10:00:00'), 'list');

    expect($result->fetchedAt)->toBeInstanceOf(CarbonImmutable::class)
        ->and($result->isStale)->toBeTrue();
});

it('counts null, empty arrays and empty collections as empty', function (mixed $data, bool $empty) {
    expect((new Result($data, null, true))->isEmpty())->toBe($empty);
})->with([
    'null' => [null, true],
    'empty array' => [[], true],
    'empty collection' => [new Collection, true],
    'collection' => [collect([1]), false],
    'model-like object' => [new stdClass, false],
]);

it('has an empty, stale result for no data', function () {
    $result = Result::empty();

    expect($result->isEmpty())->toBeTrue()
        ->and($result->isStale)->toBeTrue()
        ->and($result->fetchedAt)->toBeNull();
});
