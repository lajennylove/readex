<?php

use App\Models\Manga;
use App\Models\ViewedSeries;
use App\Repositories\ViewedSeriesRepository;
use Illuminate\Support\Carbon;

function vsManga(int $n): Manga
{
    return Manga::query()->create([
        'id' => sprintf('v0000000-0000-4000-8000-%012d', $n),
        'title' => ['en' => "Viewed {$n}"], 'alt_titles' => [], 'description' => [],
        'content_rating' => 'safe', 'available_languages' => ['en'], 'tag_ids' => [],
    ]);
}

function vsId(int $n): string
{
    return sprintf('v0000000-0000-4000-8000-%012d', $n);
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-25 12:00:00');
    $this->viewed = app(ViewedSeriesRepository::class);
});

it('records a first view and bumps last_viewed_at on a later one, first_viewed_at unchanged', function () {
    vsManga(1);

    $this->viewed->recordView(vsId(1));

    $row = ViewedSeries::query()->find(vsId(1));
    expect($row->first_viewed_at->toDateTimeString())->toBe('2026-10-25 12:00:00')
        ->and($row->last_viewed_at->toDateTimeString())->toBe('2026-10-25 12:00:00');

    Carbon::setTestNow('2026-10-25 13:00:00');
    $this->viewed->recordView(vsId(1));

    $row = ViewedSeries::query()->find(vsId(1));
    expect($row->first_viewed_at->toDateTimeString())->toBe('2026-10-25 12:00:00')
        ->and($row->last_viewed_at->toDateTimeString())->toBe('2026-10-25 13:00:00');
});

it('lists most-recently-viewed first', function () {
    foreach ([1, 2, 3] as $n) {
        vsManga($n);
    }

    $this->viewed->recordView(vsId(1));
    Carbon::setTestNow('2026-10-25 13:00:00');
    $this->viewed->recordView(vsId(2));
    Carbon::setTestNow('2026-10-25 14:00:00');
    $this->viewed->recordView(vsId(3));

    expect($this->viewed->list()->map(fn (Manga $m) => $m->title['en'])->all())
        ->toBe(['Viewed 3', 'Viewed 2', 'Viewed 1']);
});

it('caps at 10, oldest by last_viewed_at dropping off the 11th view', function () {
    foreach (range(1, 10) as $n) {
        vsManga($n);
        $this->viewed->recordView(vsId($n));
        Carbon::setTestNow(Carbon::now()->addHour());
    }

    expect(ViewedSeries::query()->count())->toBe(10);

    vsManga(11);
    $this->viewed->recordView(vsId(11));

    expect(ViewedSeries::query()->count())->toBe(10)
        ->and(ViewedSeries::query()->whereKey(vsId(1))->exists())->toBeFalse()
        ->and(ViewedSeries::query()->whereKey(vsId(11))->exists())->toBeTrue();
});

it('re-viewing an existing row does not evict anything (touch, not insert)', function () {
    foreach (range(1, 10) as $n) {
        vsManga($n);
        $this->viewed->recordView(vsId($n));
        Carbon::setTestNow(Carbon::now()->addHour());
    }

    $this->viewed->recordView(vsId(1));

    expect(ViewedSeries::query()->count())->toBe(10)
        ->and(ViewedSeries::query()->whereKey(vsId(1))->exists())->toBeTrue();
});

it('removes a row when the series is favorited', function () {
    vsManga(1);
    $this->viewed->recordView(vsId(1));

    $this->viewed->removeOnFavorite(vsId(1));

    expect(ViewedSeries::query()->whereKey(vsId(1))->exists())->toBeFalse();
});

it('removes a row when any chapter of the series is read', function () {
    vsManga(1);
    $this->viewed->recordView(vsId(1));

    $this->viewed->removeOnRead(vsId(1));

    expect(ViewedSeries::query()->whereKey(vsId(1))->exists())->toBeFalse();
});

it('removes a row manually ("Remove from this list"), and a later Detail visit re-inserts it fresh', function () {
    vsManga(1);
    $this->viewed->recordView(vsId(1));

    $this->viewed->remove(vsId(1));
    expect(ViewedSeries::query()->whereKey(vsId(1))->exists())->toBeFalse();

    Carbon::setTestNow('2026-10-25 15:00:00');
    $this->viewed->recordView(vsId(1));

    $row = ViewedSeries::query()->find(vsId(1));
    expect($row->first_viewed_at->toDateTimeString())->toBe('2026-10-25 15:00:00');
});

it('leaves a row out when its manga is missing', function () {
    $this->viewed->recordView(vsId(1)); // no manga row created

    expect($this->viewed->list())->toBeEmpty();
});
