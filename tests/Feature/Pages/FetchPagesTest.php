<?php

use App\Jobs\DownloadChapter;
use App\Jobs\EnforceCacheCap;
use App\MangaDex\RateLimit\Priority;
use App\Models\StoredChapter;
use App\Pages\FetchPages;
use App\Pages\LoadState;
use App\Pages\PageFetcher;
use App\Repositories\SettingsRepository;
use App\Storage\PageStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Native\Mobile\AsyncTask;
use Tests\Support\Covers;
use Tests\Support\Pages;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 18:30:00', 'UTC'));
    Sleep::fake(syncWithCarbon: true);
    Covers::useTempCaches();
    $this->async = AsyncTask::fake();
    Queue::fake();
    $this->fetcher = app(PageFetcher::class);
    $this->store = app(PageStore::class);
});

afterEach(fn () => AsyncTask::clearFake());

it('downloads the pages on screen right away, then queues the rest of the chapter', function () {
    Pages::fake(count: 5);
    Pages::chapter('c1', ['pages' => 5]);

    expect($this->fetcher->want('c1', [2, 3]))->toBeTrue();

    $this->async->assertDispatchedTimes(1);
    expect(Pages::requested())->toBe(['a.mangadex.network/data-saver/h1/p3.jpg', 'a.mangadex.network/data-saver/h1/p4.jpg'])
        ->and($this->store->missing('c1'))->toBe([0, 1, 4])
        ->and($this->fetcher->state('c1'))->toBe(LoadState::Done)
        ->and($this->fetcher->isRunning('c1'))->toBeFalse();
    Queue::assertPushed(DownloadChapter::class, fn (DownloadChapter $job) => $job->chapterId === 'c1'
        && $job->tier === 'cache' && $job->priority === Priority::Interactive);
});

it('queues nothing once the whole chapter is on the phone', function () {
    Pages::fake(count: 2);
    Pages::chapter('c1', ['pages' => 2]);

    $this->fetcher->want('c1', [0, 1]);

    expect($this->store->has('c1'))->toBeTrue();
    Queue::assertNotPushed(DownloadChapter::class);
});

it('queues the cache cap once after a run that stored pages, not per page', function () {
    Pages::fake(count: 3);
    Pages::chapter('c1', ['pages' => 3]);

    $this->fetcher->want('c1', [0, 1, 2]);

    Queue::assertPushed(EnforceCacheCap::class, 1);
});

it('queues no cache cap after a run that stored nothing', function () {
    Http::fake(fn () => throw new ConnectionException('offline'));
    Pages::chapter('c1');

    $this->fetcher->want('c1', [0]);

    expect($this->fetcher->state('c1'))->toBe(LoadState::Offline);
    Queue::assertNotPushed(EnforceCacheCap::class);
});

it('never touches the network for pages already stored (Airplane mode, read yesterday)', function () {
    Pages::fake(count: 2);
    Pages::chapter('c1', ['pages' => 2]);
    $this->fetcher->want('c1', [0, 1]);
    Http::fake(fn () => throw new ConnectionException('offline'));

    expect($this->fetcher->want('c1', [0, 1]))->toBeFalse();

    $this->async->assertDispatchedTimes(1);
    Http::assertNothingSent();
});

it('follows a fast scroll: one task per chapter, which moves to the new window', function () {
    $scrolled = false;
    Pages::fake(count: 200, image: function () use (&$scrolled) {
        if (! $scrolled) {
            $scrolled = true;
            expect(app(PageFetcher::class)->want('c1', [150, 151]))->toBeFalse();
        }

        return Http::response(Pages::image());
    });
    Pages::chapter('c1', ['pages' => 200]);

    $this->fetcher->want('c1', range(0, 9));

    $this->async->assertDispatchedTimes(1);
    expect(Pages::requested())->toBe([
        'a.mangadex.network/data-saver/h1/p1.jpg',
        'a.mangadex.network/data-saver/h1/p151.jpg',
        'a.mangadex.network/data-saver/h1/p152.jpg',
    ])->and(Pages::lookups())->toBe(1);
});

it('starts no second task while one runs for the chapter', function () {
    Pages::fake();
    Pages::chapter('c1');
    $this->fetcher->want('c1', [0]);
    cache()->put('readex.pages.running.c1', true, 60);

    expect($this->fetcher->want('c1', [1]))->toBeFalse();

    $this->async->assertDispatchedTimes(1);
});

it('looks the @Home server up again once when it dies mid-chapter, then carries on there', function () {
    Pages::fake(['https://dead.mangadex.network', 'https://alive.mangadex.network'], 3, function (Request $request) {
        return str_contains($request->url(), 'dead.') ? Http::response('', 502) : Http::response(Pages::image());
    });
    Pages::chapter('c1');

    $this->fetcher->want('c1', [0, 1, 2]);

    expect(Pages::requested())->toBe([
        'dead.mangadex.network/data-saver/h1/p1.jpg',
        'alive.mangadex.network/data-saver/h1/p1.jpg',
        'alive.mangadex.network/data-saver/h1/p2.jpg',
        'alive.mangadex.network/data-saver/h1/p3.jpg',
    ])->and(Pages::lookups())->toBe(2)
        ->and($this->store->has('c1'))->toBeTrue()
        ->and($this->fetcher->failed('c1'))->toBe([]);
});

it('marks a page failed after the fresh lookup and one retry; one lookup per run, the rest keep loading', function () {
    Pages::fake(count: 4, image: fn (Request $request) => match (true) {
        str_ends_with($request->url(), '/p2.jpg') => Http::response('', 500),
        str_ends_with($request->url(), '/p3.jpg') => Http::response('<html>Wi-Fi login</html>'),
        default => Http::response(Pages::image()),
    });
    Pages::chapter('c1', ['pages' => 4]);

    $this->fetcher->want('c1', [0, 1, 2, 3]);

    expect($this->fetcher->failed('c1'))->toBe([1, 2])
        ->and(Pages::lookups())->toBe(2)
        ->and($this->store->missing('c1'))->toBe([1, 2])
        ->and($this->fetcher->state('c1'))->toBe(LoadState::Done)
        ->and(collect(Pages::requested())->filter(fn ($url) => str_ends_with($url, '/p3.jpg'))->count())->toBe(1);
    Queue::assertNotPushed(DownloadChapter::class);
});

it('does not ask for a failed page again until Tap to retry', function () {
    $broken = true;
    Pages::fake(image: function (Request $request) use (&$broken) {
        return $broken && str_ends_with($request->url(), '/p2.jpg') ? Http::response('', 500) : Http::response(Pages::image());
    });
    Pages::chapter('c1');
    $this->fetcher->want('c1', [0, 1, 2]);

    expect($this->fetcher->want('c1', [0, 1, 2]))->toBeFalse();

    $broken = false;
    expect($this->fetcher->retry('c1', 1))->toBeTrue()
        ->and($this->store->has('c1'))->toBeTrue()
        ->and($this->fetcher->failed('c1'))->toBe([]);
});

it('is offline, not failed, when the phone has no connection (chapter never opened)', function () {
    Http::fake(fn () => throw new ConnectionException('offline'));
    Pages::chapter('c1');

    $this->fetcher->want('c1', [0, 1]);

    expect($this->fetcher->state('c1'))->toBe(LoadState::Offline)
        ->and($this->fetcher->failed('c1'))->toBe([])
        ->and(StoredChapter::query()->count())->toBe(0)
        ->and(DB::table('reading_progress')->count())->toBe(0);
    Queue::assertPushed(DownloadChapter::class, 1);
});

it('stops as offline when the connection drops mid-chapter, without marking pages failed', function () {
    $online = true;
    $lookups = 0;
    Http::fake(function (Request $request) use (&$online, &$lookups) {
        $lookups += str_contains($request->url(), '/at-home/server/') ? 1 : 0;

        if (! $online) {
            throw new ConnectionException('offline');
        }

        if (str_contains($request->url(), '/at-home/server/')) {
            return Http::response(Pages::atHome('https://a.mangadex.network'));
        }

        $online = false;

        return Http::response(Pages::image());
    });
    Pages::chapter('c1');

    $this->fetcher->want('c1', [0, 1, 2]);

    expect($this->fetcher->state('c1'))->toBe(LoadState::Offline)
        ->and($this->fetcher->failed('c1'))->toBe([])
        ->and($this->store->missing('c1'))->toBe([1, 2])
        ->and($lookups)->toBe(2);
});

it('stops as offline when the connection drops after the fresh lookup, without marking the page failed', function () {
    Pages::fake(['https://dead.mangadex.network', 'https://alive.mangadex.network'], 3, function (Request $request) {
        if (str_contains($request->url(), 'dead.')) {
            return Http::response('', 502);
        }

        if (str_ends_with($request->url(), '/p2.jpg')) {
            throw new ConnectionException('offline');
        }

        return Http::response(Pages::image());
    });
    Pages::chapter('c1');

    $this->fetcher->want('c1', [0, 1, 2]);

    expect($this->fetcher->state('c1'))->toBe(LoadState::Offline)
        ->and($this->fetcher->failed('c1'))->toBe([])
        ->and($this->store->missing('c1'))->toBe([1, 2])
        ->and(Pages::lookups())->toBe(2);
    Queue::assertPushed(DownloadChapter::class, 1);
});

it('waits for the controller when MangaDex has no slot, and queues the chapter', function () {
    Pages::fake();
    Pages::chapter('c1');
    DB::table('rate_buckets')->insert([
        'key' => 'family:mangadex.org', 'capacity' => 0, 'refill_per_sec' => 0, 'tokens' => 0,
        'updated_at' => now()->timestamp, 'blocked_until' => now()->timestamp + 300, 'strikes' => 0, 'probing' => false,
    ]);

    $this->fetcher->want('c1', [0]);

    Http::assertNothingSent();
    expect($this->fetcher->state('c1'))->toBe(LoadState::Busy);
    Queue::assertPushed(DownloadChapter::class, fn (DownloadChapter $job) => $job->priority === Priority::Interactive);
});

it('finishes a half-stored chapter in its first quality; the next chapter uses the new setting', function () {
    Pages::fake();
    Pages::chapter('c1');
    Pages::chapter('c2');
    $this->fetcher->want('c1', [0]);

    app(SettingsRepository::class)->setImageQuality('original');
    $this->fetcher->want('c1', [1, 2]);
    $this->fetcher->want('c2', [0]);

    expect(Pages::requested())->toBe([
        'a.mangadex.network/data-saver/h1/p1.jpg',
        'a.mangadex.network/data-saver/h1/p2.jpg',
        'a.mangadex.network/data-saver/h1/p3.jpg',
        'a.mangadex.network/data/h1/p1.png',
    ])->and($this->store->manifest('c1')['quality'])->toBe('data-saver')
        ->and($this->store->manifest('c2')['quality'])->toBe('original');
});

it('is unavailable for an external chapter, without a call', function () {
    Pages::fake();
    Pages::chapter('c1', ['external_url' => 'https://mangaplus.shueisha.co.jp/viewer/1']);

    $this->fetcher->want('c1', [0]);

    Http::assertNothingSent();
    expect($this->fetcher->state('c1'))->toBe(LoadState::Unavailable);
    Queue::assertNothingPushed();
});

it('is unavailable when MangaDex no longer serves the chapter', function () {
    Http::fake(['api.mangadex.org/*' => Http::response(['result' => 'error', 'errors' => [['status' => 404]]], 404)]);
    Pages::chapter('c1');

    $this->fetcher->want('c1', [0]);

    expect($this->fetcher->state('c1'))->toBe(LoadState::Unavailable);
    Queue::assertNothingPushed();
});

it('is unavailable for any other 4xx answer to the lookup', function (int $status) {
    Http::fake(['api.mangadex.org/*' => Http::response(['result' => 'error', 'errors' => [['status' => $status]]], $status)]);
    Pages::chapter('c1');

    $this->fetcher->want('c1', [0]);

    expect($this->fetcher->state('c1'))->toBe(LoadState::Unavailable);
    Queue::assertNothingPushed();
})->with([400, 410]);

it('is retrying, not unavailable, when the lookup meets a MangaDex outage, limit or ban, and queues the chapter', function (int $status, array|string $body) {
    Http::fake(['api.mangadex.org/at-home/server/*' => Http::response($body, $status)]);
    Pages::chapter('c1');

    $this->fetcher->want('c1', [0]);

    expect($this->fetcher->state('c1'))->toBe(LoadState::Retrying)
        ->and($this->fetcher->failed('c1'))->toBe([])
        ->and(StoredChapter::query()->count())->toBe(0);
    Queue::assertPushed(DownloadChapter::class, fn (DownloadChapter $job) => $job->chapterId === 'c1' && $job->priority === Priority::Interactive);
})->with([
    '503' => [503, ['result' => 'error', 'errors' => [['status' => 503, 'title' => 'Service Unavailable']]]],
    '500' => [500, ''],
    '429' => [429, ['result' => 'error', 'errors' => [['status' => 429]]]],
    '403 ban' => [403, '<html>Forbidden</html>'],
    '403 error' => [403, ['result' => 'error', 'errors' => [['status' => 403]]]],
]);

it('is retrying, without marking the page failed, when the fresh lookup after a dead node meets a 503', function () {
    $lookups = 0;
    Http::fake(function (Request $request) use (&$lookups) {
        if (str_contains($request->url(), '/at-home/server/')) {
            return $lookups++ === 0 ? Http::response(Pages::atHome('https://dead.mangadex.network')) : Http::response('', 503);
        }

        return str_contains($request->url(), 'dead.') ? Http::response('', 502) : Http::response(['result' => 'ok']);
    });
    Pages::chapter('c1');

    $this->fetcher->want('c1', [0, 1]);

    expect($this->fetcher->state('c1'))->toBe(LoadState::Retrying)
        ->and($this->fetcher->failed('c1'))->toBe([])
        ->and($this->store->missing('c1'))->toBe([0, 1, 2])
        ->and($lookups)->toBe(2);
    Queue::assertPushed(DownloadChapter::class, 1);
});

it('is unavailable when the @Home lookup lists no pages, and saves nothing', function () {
    Http::fake(['api.mangadex.org/at-home/server/*' => Http::response(['result' => 'ok', 'baseUrl' => 'https://a.mangadex.network', 'chapter' => [
        'hash' => 'h1', 'data' => [], 'dataSaver' => [],
    ]])]);
    Pages::chapter('c1');

    $this->fetcher->want('c1', [0]);

    expect($this->fetcher->state('c1'))->toBe(LoadState::Unavailable)
        ->and($this->store->manifest('c1'))->toBeNull()
        ->and(StoredChapter::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('hands the rest to the queue once its time budget is used up', function () {
    Pages::fake(count: 5, image: function () {
        Carbon::setTestNow(Carbon::now()->addSeconds(config('readex.pages.task_budget_seconds')));

        return Http::response(Pages::image());
    });
    Pages::chapter('c1', ['pages' => 5]);

    $this->fetcher->want('c1', [0, 1, 2]);

    expect($this->store->missing('c1'))->toBe([1, 2, 3, 4]);
    Queue::assertPushed(DownloadChapter::class, 1);
});

it('never throws: an unexpected error is reported and the next want() starts again', function () {
    Exceptions::fake();
    Http::fake(fn () => throw new RuntimeException('boom'));
    Pages::chapter('c1');
    $this->fetcher->want('c1', [0]);

    $result = (new FetchPages)->handle('c1');

    expect($result)->toBe(['state' => 'failed', 'fetched' => 0, 'queued' => false])
        ->and($this->fetcher->state('c1'))->toBe(LoadState::Failed)
        ->and($this->fetcher->isRunning('c1'))->toBeFalse()
        ->and($this->fetcher->want('c1', [0]))->toBeTrue();
    Exceptions::assertReported(RuntimeException::class);
    Queue::assertNothingPushed();
});
