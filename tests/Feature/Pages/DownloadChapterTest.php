<?php

use App\Adapters\Http\RateLimit\Priority;
use App\Downloads\Downloads;
use App\Jobs\DownloadChapter;
use App\Jobs\EnforceCacheCap;
use App\Models\StoredChapter;
use App\Pages\LoadState;
use App\Pages\PageFetcher;
use App\Storage\ImageCache;
use App\Storage\PageStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\Covers;
use Tests\Support\Pages;
use Tests\Support\Stored;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 18:30:00', 'UTC'));
    Sleep::fake(syncWithCarbon: true);
    $this->caches = Covers::useTempCaches();
    Queue::fake();
    $this->store = app(PageStore::class);
    $this->fetcher = app(PageFetcher::class);
});

function runChapter(string $id, string $tier = 'cache', Priority $priority = Priority::Prefetch): DownloadChapter
{
    $job = (new DownloadChapter($id, $tier, $priority))->withFakeQueueInteractions();
    $job->handle(app(PageFetcher::class));

    return $job;
}

it('downloads every page in reading order and marks the chapter done', function () {
    Pages::fake(count: 4);
    Pages::chapter('c1', ['pages' => 4]);

    runChapter('c1')->assertNotReleased();

    expect(Pages::requested())->toBe(array_map(fn ($i) => "a.source.test/data-saver/h1/p{$i}.jpg", [1, 2, 3, 4]))
        ->and($this->store->has('c1'))->toBeTrue()
        ->and($this->store->manifest('c1')['pages'][3]['width'])->toBe(4)
        ->and(DB::table('stored_chapters')->where('chapter_id', 'c1')->value('status'))->toBe('done')
        ->and($this->fetcher->state('c1'))->toBe(LoadState::Done);
});

it('resumes from what is on disk, also after iOS purged a page', function () {
    Pages::fake(count: 3);
    Pages::chapter('c1');
    runChapter('c1');
    unlink("{$this->caches}/chapters/c1/002.jpg");
    Http::swap(new Factory(app('events')));
    Pages::fake(count: 3);

    runChapter('c1');

    expect(Pages::requested())->toBe(['a.source.test/data-saver/h1/p2.jpg'])
        ->and(Pages::lookups())->toBe(0)
        ->and($this->store->has('c1'))->toBeTrue();
});

it('leaves a page another fetcher is downloading to it and checks back in 5 s', function () {
    Pages::fake(count: 3);
    Pages::chapter('c1');
    cache()->add('readex.pages.claim.c1.1', true, 60);

    runChapter('c1')->assertReleased(5);

    expect($this->store->missing('c1'))->toBe([1])
        ->and(Pages::requested())->not->toContain('a.source.test/data-saver/h1/p2.jpg')
        ->and($this->fetcher->state('c1'))->toBe(LoadState::Busy);
});

it('stores a download into the local tier', function () {
    Stored::useTempStorage();
    Pages::fake(count: 1);
    Pages::chapter('c1', ['pages' => 1]);
    app(Downloads::class)->download('c1');

    runChapter('c1', 'local');

    expect($this->store->pathFor('c1', 0))->toBe('storage/downloads/c1/001.jpg');
});

it('queues the cache cap once at the end of a cache run, not per page', function () {
    Pages::fake(count: 4);
    Pages::chapter('c1', ['pages' => 4]);

    runChapter('c1');

    Queue::assertPushed(EnforceCacheCap::class, 1);
});

it('queues the cache cap when a cache run stops early too, and nothing for a download or a chapter it never ran', function () {
    Http::fake(fn () => throw new ConnectionException('offline'));
    Pages::chapter('c1');
    Pages::chapter('c2', ['external_url' => 'https://mangaplus.shueisha.co.jp/viewer/1']);

    runChapter('c1')->assertReleased(DownloadChapter::OFFLINE_RETRY_SECONDS);
    Queue::assertPushed(EnforceCacheCap::class, 1);

    Queue::fake();
    runChapter('c2');
    runChapter('gone');
    Queue::assertNotPushed(EnforceCacheCap::class);

    Stored::useTempStorage();
    Http::swap(new Factory(app('events')));
    Pages::fake(count: 3);
    Pages::chapter('c3');
    app(Downloads::class)->download('c3');

    runChapter('c3', 'local');
    expect($this->store->pathFor('c3', 2))->toBe('storage/downloads/c3/003.jpg');
    Queue::assertNotPushed(EnforceCacheCap::class);
});

it('stops taking pages after 40 s and releases itself to continue', function () {
    $seconds = [39, 1, 1];
    Pages::fake(count: 3, image: function () use (&$seconds) {
        $this->travel(array_shift($seconds))->seconds();

        return Http::response(Pages::image());
    });
    Pages::chapter('c1');

    runChapter('c1')->assertReleased(0);

    expect(Pages::requested())->toBe(['a.source.test/data-saver/h1/p1.jpg', 'a.source.test/data-saver/h1/p2.jpg'])
        ->and($this->store->missing('c1'))->toBe([2])
        ->and($this->fetcher->state('c1'))->toBe(LoadState::Busy)
        ->and(DB::table('stored_chapters')->where('chapter_id', 'c1')->value('status'))->toBe('partial');
});

it('continues the next slice from the first missing page and finishes it', function () {
    Pages::fake(count: 5, image: function () {
        $this->travel(20)->seconds();

        return Http::response(Pages::image());
    });
    Pages::chapter('c1', ['pages' => 5]);
    runChapter('c1')->assertReleased(0);
    Http::swap(new Factory(app('events')));
    Pages::fake(count: 5);

    runChapter('c1')->assertNotReleased();

    expect(Pages::requested())->toBe(array_map(fn ($i) => "a.source.test/data-saver/h1/p{$i}.jpg", [3, 4, 5]))
        ->and(Pages::lookups())->toBe(0)
        ->and($this->store->missing('c1'))->toBe([])
        ->and($this->fetcher->state('c1'))->toBe(LoadState::Done)
        ->and(DB::table('stored_chapters')->where('chapter_id', 'c1')->value('status'))->toBe('done');
});

it('waits for the controller when the source is limiting the phone', function () {
    Pages::fake();
    Pages::chapter('c1');
    DB::table('rate_buckets')->insert([
        'key' => 'family:api.source.test', 'capacity' => 0, 'refill_per_sec' => 0, 'tokens' => 0,
        'updated_at' => now()->timestamp, 'blocked_until' => now()->timestamp + 300, 'strikes' => 0, 'probing' => false,
    ]);

    runChapter('c1')->assertReleased(300);

    Http::assertNothingSent();
    expect($this->fetcher->state('c1'))->toBe(LoadState::Busy);
});

it('tries again in a minute when the phone is offline', function () {
    Http::fake(fn () => throw new ConnectionException('offline'));
    Pages::chapter('c1');

    runChapter('c1')->assertReleased(DownloadChapter::OFFLINE_RETRY_SECONDS);

    expect($this->fetcher->state('c1'))->toBe(LoadState::Offline);
});

it('stops for good when the source no longer serves the chapter, or it is external', function () {
    Http::fake(['api.source.test/*' => Http::response(['result' => 'error'], 404)]);
    Pages::chapter('c1');
    Pages::chapter('c2', ['external_url' => 'https://mangaplus.shueisha.co.jp/viewer/1']);

    runChapter('c1')->assertNotReleased();
    runChapter('c2')->assertNotReleased();
    runChapter('gone')->assertNotReleased();

    expect($this->fetcher->state('c1'))->toBe(LoadState::Unavailable);
    Http::assertSentCount(1);
});

it('waits out a the source outage, limit or ban on the lookup: released after 1 s, 4 s, 15 s, then every 60 s, the download still queued', function (int $status, array|string $body) {
    Stored::useTempStorage();
    Http::fake(['api.source.test/api/at-home/server/*' => Http::response($body, $status)]);
    Pages::chapter('c1');
    app(Downloads::class)->download('c1');

    foreach ([1, 4, 15, 60, 60] as $seconds) {
        DB::table('rate_buckets')->delete();
        runChapter('c1', 'local')->assertReleased($seconds);
    }

    expect($this->fetcher->state('c1'))->toBe(LoadState::Retrying)
        ->and($this->fetcher->failed('c1'))->toBe([])
        ->and(app(Downloads::class)->isQueued('c1'))->toBeTrue();
})->with([
    '503' => [503, ['result' => 'error', 'errors' => [['status' => 503]]]],
    '429' => [429, ['result' => 'error', 'errors' => [['status' => 429]]]],
    '403 ban' => [403, '<html>Forbidden</html>'],
]);

it('starts the outage backoff over after a slice that got through', function () {
    $lookups = 0;
    Http::fake(function (Request $request) use (&$lookups) {
        if (str_contains($request->url(), '/at-home/server/')) {
            return match ($lookups++) {
                0 => Http::response('', 503),
                1 => Http::response(Pages::atHome('https://dead.source.test')),
                default => Http::response('', 503),
            };
        }

        if (str_ends_with($request->url(), '/p1.jpg')) {
            $this->travel(40)->seconds();

            return Http::response(Pages::image());
        }

        return str_contains($request->url(), 'api.source.test') ? Http::response(['result' => 'ok']) : Http::response('', 502);
    });
    Pages::chapter('c1');

    runChapter('c1')->assertReleased(1);
    runChapter('c1')->assertReleased(0);
    runChapter('c1')->assertReleased(1);

    expect($this->store->missing('c1'))->toBe([1, 2])
        ->and($this->fetcher->failed('c1'))->toBe([]);
});

describe('Clear image cache or the cap while it runs', function () {
    it('stops at once when the chapter is dropped between two pages: nothing marked failed, no second lookup, nothing begun again', function () {
        Pages::fake(count: 3, image: function (Request $request) {
            if (str_ends_with($request->url(), '/p2.jpg')) {
                app(ImageCache::class)->clear();
            }

            return Http::response(Pages::image());
        });
        Pages::chapter('c1');

        runChapter('c1')->assertNotReleased();

        expect(Pages::requested())->toBe(['a.source.test/data-saver/h1/p1.jpg', 'a.source.test/data-saver/h1/p2.jpg'])
            ->and(Pages::lookups())->toBe(1)
            ->and($this->fetcher->failed('c1'))->toBe([])
            ->and(StoredChapter::query()->count())->toBe(0)
            ->and(is_dir("{$this->caches}/chapters/c1"))->toBeFalse();

        runChapter('c1')->assertNotReleased();

        expect(Pages::requested())->toHaveCount(2)
            ->and(StoredChapter::query()->count())->toBe(0);
    });

    it('does not begin the chapter again in the slice after the one it was dropped behind', function () {
        Pages::fake(count: 3, image: function () {
            $this->travel(40)->seconds();

            return Http::response(Pages::image());
        });
        Pages::chapter('c1');
        runChapter('c1')->assertReleased(0);

        config(['readex.cache.cap_bytes' => 0]);
        app(ImageCache::class)->enforce();
        $this->travel(11)->minutes();
        runChapter('c1')->assertNotReleased();

        expect(Pages::requested())->toHaveCount(1)
            ->and(Pages::lookups())->toBe(1)
            ->and(StoredChapter::query()->count())->toBe(0);
    });

    it('does not begin the chapter again when it is dropped while the slice looks @Home up', function () {
        Stored::partial('c1', 1, 3);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/at-home/server/')) {
                app(ImageCache::class)->clear();

                return Http::response(Pages::atHome('https://a.source.test'));
            }

            return Http::response(Pages::image());
        });

        runChapter('c1')->assertNotReleased();

        expect(Pages::requested())->toBe([])
            ->and(StoredChapter::query()->count())->toBe(0)
            ->and(is_dir("{$this->caches}/chapters/c1"))->toBeFalse();
    });

    it('starts a download over in the downloads next slice if its chapter vanishes mid-run, still queued', function () {
        Stored::useTempStorage();
        Pages::fake(count: 3, image: function (Request $request) {
            if (str_ends_with($request->url(), '/p2.jpg')) {
                StoredChapter::query()->whereKey('c1')->delete();
            }

            return Http::response(Pages::image());
        });
        Pages::chapter('c1');
        app(Downloads::class)->download('c1');

        runChapter('c1', 'local')->assertReleased(0);

        expect($this->fetcher->failed('c1'))->toBe([])
            ->and(app(Downloads::class)->isQueued('c1'))->toBeTrue();
    });

    it('lets a Download begin a dropped chapter again, in the downloads', function () {
        Stored::useTempStorage();
        Pages::fake(count: 2);
        Stored::chapter('c1', 2);
        app(ImageCache::class)->clear();

        app(Downloads::class)->download('c1');
        runChapter('c1', 'local');

        expect($this->store->pathFor('c1', 1))->toBe('storage/downloads/c1/002.jpg')
            ->and(StoredChapter::query()->find('c1')->tier)->toBe('local');
    });
});

it('is unique per chapter, prefetch by default, and gives up after an hour', function () {
    $job = new DownloadChapter('c1');

    expect($job->uniqueId())->toBe('c1')
        ->and($job->tier)->toBe('cache')
        ->and($job->priority)->toBe(Priority::Prefetch)
        ->and($job->retryUntil()->toDateTimeString())->toBe('2026-10-23 19:30:00');
});

it('is stopped by the worker after 80 s, before the database queue hands it out again', function () {
    $job = new DownloadChapter('c1');

    expect($job->timeout)->toBe(80)
        ->and($job->timeout)->toBeLessThan(config('queue.connections.database.retry_after'));
});
