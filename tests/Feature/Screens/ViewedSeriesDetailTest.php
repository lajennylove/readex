<?php

use App\Models\Chapter;
use App\Models\Manga;
use App\Models\ViewedSeries;
use App\Repositories\LibraryRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\ViewedSeriesRepository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Native\Mobile\AsyncTask;
use Native\Mobile\Testing\Native;
use Tests\Support\Covers;
use Tests\Support\SourceFixtures;

const VSD_RTW = 'aafb046c-9de4-4c3d-990c-6d75b079c0b9';

beforeEach(function () {
    Carbon::setTestNow('2026-10-25 12:00:00');
    Sleep::fake(syncWithCarbon: true);
    Covers::useTempCaches();
    app(SettingsRepository::class)->setLanguage('es-la');
    AsyncTask::fake();
    Queue::fake();
    $fixtures = SourceFixtures::stub('manga-rtw', 'statistics-rtw', 'feed-rtw-es-la-p1', 'feed-rtw-es-la-p2', 'recommendations-rtw', 'recommendations-rtw-manga');
    Http::fake(fn (Request $request) => $fixtures($request));
});

afterEach(fn () => AsyncTask::clearFake());

it('records a view the moment Detail mounts successfully', function () {
    expect(ViewedSeries::query()->whereKey(VSD_RTW)->exists())->toBeFalse();

    Native::visit('/search/manga/'.VSD_RTW);

    $row = ViewedSeries::query()->find(VSD_RTW);
    expect($row)->not->toBeNull()
        ->and($row->first_viewed_at)->not->toBeNull()
        ->and($row->first_viewed_at->toDateTimeString())->toBe($row->last_viewed_at->toDateTimeString());
});

it('bumps last_viewed_at on a later Detail visit, without duplicating the row', function () {
    Native::visit('/search/manga/'.VSD_RTW);
    $firstViewedAt = ViewedSeries::query()->find(VSD_RTW)->first_viewed_at;
    Carbon::setTestNow(Carbon::now()->addHour());
    Native::visit('/search/manga/'.VSD_RTW);

    $row = ViewedSeries::query()->find(VSD_RTW);
    expect(ViewedSeries::query()->count())->toBe(1)
        ->and($row->first_viewed_at->toDateTimeString())->toBe($firstViewedAt->toDateTimeString())
        ->and($row->last_viewed_at->getTimestamp())->toBeGreaterThan($firstViewedAt->getTimestamp());
});

it('favoriting the series removes it from the interest row', function () {
    Native::visit('/search/manga/'.VSD_RTW);
    expect(ViewedSeries::query()->whereKey(VSD_RTW)->exists())->toBeTrue();

    app(LibraryRepository::class)->setFavorite(VSD_RTW, true);

    expect(ViewedSeries::query()->whereKey(VSD_RTW)->exists())->toBeFalse();
});

it('reading any chapter of the series removes it from the interest row', function () {
    Native::visit('/search/manga/'.VSD_RTW);
    expect(ViewedSeries::query()->whereKey(VSD_RTW)->exists())->toBeTrue();

    $chapterId = Chapter::query()->where('manga_id', VSD_RTW)->value('id');
    app(LibraryRepository::class)->saveProgress($chapterId, 0, 20);

    expect(ViewedSeries::query()->whereKey(VSD_RTW)->exists())->toBeFalse();
});

it('the interest row lists Manga models ordered most-recently-viewed first, capped at 10', function () {
    foreach (range(1, 3) as $n) {
        Manga::query()->create([
            'id' => sprintf('vd000000-0000-4000-8000-%012d', $n), 'title' => ['en' => "VD {$n}"],
            'alt_titles' => [], 'description' => [], 'content_rating' => 'safe',
            'available_languages' => ['en'], 'tag_ids' => [],
        ]);
    }
    $viewed = app(ViewedSeriesRepository::class);
    $viewed->recordView(sprintf('vd000000-0000-4000-8000-%012d', 1));
    Carbon::setTestNow('2026-10-25 13:00:00');
    $viewed->recordView(sprintf('vd000000-0000-4000-8000-%012d', 2));

    expect($viewed->list()->map(fn (Manga $m) => $m->title['en'])->all())->toBe(['VD 2', 'VD 1']);
});
