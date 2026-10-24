<?php

use App\Jobs\DownloadChapter;
use App\Jobs\EnforceCacheCap;
use App\MangaDex\RateLimit\Priority;
use App\Pages\LoadState;
use App\Pages\PageFetcher;
use App\Storage\PageStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Covers;
use Tests\Support\Pages;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 18:30:00', 'UTC'));
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

    expect(Pages::requested())->toBe(array_map(fn ($i) => "a.mangadex.network/data-saver/h1/p{$i}.jpg", [1, 2, 3, 4]))
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

    expect(Pages::requested())->toBe(['a.mangadex.network/data-saver/h1/p2.jpg'])
        ->and(Pages::lookups())->toBe(0)
        ->and($this->store->has('c1'))->toBeTrue();
});

it('leaves a page another fetcher is downloading to it', function () {
    Pages::fake(count: 3);
    Pages::chapter('c1');
    cache()->add('readex.pages.claim.c1.1', true, 60);

    runChapter('c1');

    expect($this->store->missing('c1'))->toBe([1])
        ->and(Pages::requested())->not->toContain('a.mangadex.network/data-saver/h1/p2.jpg');
});

it('stores into the tier it was given', function () {
    Pages::fake(count: 1);
    Pages::chapter('c1', ['pages' => 1]);

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

    $storage = sys_get_temp_dir().'/readex-storage-'.bin2hex(random_bytes(4));
    app()->useStoragePath($storage);
    register_shutdown_function(fn () => File::deleteDirectory($storage));
    Http::swap(new Factory(app('events')));
    Pages::fake(count: 3);
    Pages::chapter('c3');

    runChapter('c3', 'local');
    Queue::assertNotPushed(EnforceCacheCap::class);
});

it('waits for the controller when MangaDex is limiting the phone', function () {
    Pages::fake();
    Pages::chapter('c1');
    DB::table('rate_buckets')->insert([
        'key' => 'family:mangadex.org', 'capacity' => 0, 'refill_per_sec' => 0, 'tokens' => 0,
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

it('stops for good when MangaDex no longer serves the chapter, or it is external', function () {
    Http::fake(['api.mangadex.org/*' => Http::response(['result' => 'error'], 404)]);
    Pages::chapter('c1');
    Pages::chapter('c2', ['external_url' => 'https://mangaplus.shueisha.co.jp/viewer/1']);

    runChapter('c1')->assertNotReleased();
    runChapter('c2')->assertNotReleased();
    runChapter('gone')->assertNotReleased();

    expect($this->fetcher->state('c1'))->toBe(LoadState::Unavailable);
    Http::assertSentCount(1);
});

it('is unique per chapter, prefetch by default, and gives up after an hour', function () {
    $job = new DownloadChapter('c1');

    expect($job->uniqueId())->toBe('c1')
        ->and($job->tier)->toBe('cache')
        ->and($job->priority)->toBe(Priority::Prefetch)
        ->and($job->retryUntil()->toDateTimeString())->toBe('2026-10-23 19:30:00');
});
