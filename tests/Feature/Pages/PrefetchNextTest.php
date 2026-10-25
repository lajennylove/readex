<?php

use App\Adapters\Http\RateLimit\Priority;
use App\Jobs\DownloadChapter;
use App\Jobs\PrefetchNext;
use App\Models\Chapter;
use App\Repositories\LibraryRepository;
use App\Storage\PageStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Covers;
use Tests\Support\Pages;
use Tests\Support\Stored;

beforeEach(function () {
    Covers::useTempCaches();
    Queue::fake();
});

/** Chapters 1…`$count` of series t6-pm, ids t6-p1…. */
function prefetchSeries(int $count = 6): void
{
    foreach (range(1, $count) as $number) {
        Pages::chapter("t6-p{$number}", ['manga_id' => 't6-pm', 'number' => (string) $number]);
    }
}

/** @return list<string> */
function prefetched(): array
{
    return Queue::pushed(DownloadChapter::class)->map(fn (DownloadChapter $job) => $job->chapterId)->values()->all();
}

function runPrefetch(string $from = 't6-p1', string $manga = 't6-pm'): void
{
    (new PrefetchNext($manga, $from))->handle(app(LibraryRepository::class), app(PageStore::class));
}

it('queues the next two chapters into the cache tier at prefetch priority', function () {
    prefetchSeries();

    runPrefetch();

    expect(prefetched())->toBe(['t6-p2', 't6-p3']);
    Queue::assertPushed(DownloadChapter::class, 2);
    Queue::assertPushed(DownloadChapter::class, fn (DownloadChapter $job) => $job->tier === 'cache' && $job->priority === Priority::Prefetch);
});

it('follows the chapters of the reader\'s language only', function () {
    prefetchSeries();
    Pages::chapter('t6-p-en', ['manga_id' => 't6-pm', 'number' => '2', 'language' => 'en']);

    runPrefetch();

    expect(prefetched())->toBe(['t6-p2', 't6-p3']);
});

it('skips licensed chapters without counting them', function () {
    prefetchSeries();
    Chapter::query()->find('t6-p2')->update(['external_url' => 'https://mangaplus.shueisha.co.jp/viewer/2']);

    runPrefetch();

    expect(prefetched())->toBe(['t6-p3', 't6-p4']);
});

it('skips a chapter already on the phone in any tier, and it counts as one of the two', function () {
    prefetchSeries();
    Stored::chapter('t6-p2');

    runPrefetch();

    expect(prefetched())->toBe(['t6-p3']);

    Queue::fake();
    $storage = sys_get_temp_dir().'/readex-storage-'.bin2hex(random_bytes(4));
    app()->useStoragePath($storage);
    register_shutdown_function(fn () => File::deleteDirectory($storage));
    Stored::chapter('t6-p3', tier: 'local');

    runPrefetch();

    expect(prefetched())->toBe([]);
});

it('finishes a chapter that is only partly stored', function () {
    prefetchSeries();
    $store = app(PageStore::class);
    $store->begin(Chapter::query()->find('t6-p2'), 'data-saver', 3);
    $store->put('t6-p2', 0, Pages::image());

    runPrefetch();

    expect(prefetched())->toBe(['t6-p2', 't6-p3']);
});

it('counts stored chapters and skips licensed ones, in reading order', function () {
    prefetchSeries();
    Chapter::query()->find('t6-p2')->update(['external_url' => 'https://mangaplus.shueisha.co.jp/viewer/2']);
    Stored::chapter('t6-p3');

    runPrefetch();

    expect(prefetched())->toBe(['t6-p4']);
});

it('queues what is left near the end of the series, and nothing after the last chapter', function () {
    prefetchSeries();

    runPrefetch('t6-p5');
    expect(prefetched())->toBe(['t6-p6']);

    Queue::fake();
    runPrefetch('t6-p6');
    expect(prefetched())->toBe([]);
});

it('queues nothing for a chapter or series it does not know', function () {
    prefetchSeries();

    runPrefetch('t6-nope');
    runPrefetch('t6-p1', 't6-other');

    expect(prefetched())->toBe([]);
});

describe('the connection rule', function () {
    it('prefetches on Wi-Fi and Ethernet, with or without the iOS-only fields', function (?array $status) {
        prefetchSeries();
        Stored::network($status);

        expect(PrefetchNext::dispatchIfUnmetered('t6-pm', 't6-p1'))->toBeTrue();
        Queue::assertPushed(PrefetchNext::class, fn (PrefetchNext $job) => $job->mangaId === 't6-pm' && $job->fromChapterId === 't6-p1');
    })->with([
        'wifi' => [Stored::wifi()],
        'ethernet' => [['connected' => true, 'type' => 'ethernet', 'isExpensive' => false, 'isConstrained' => false]],
        'android wifi, no flags' => [['connected' => true, 'type' => 'wifi']],
        'flags null' => [['connected' => true, 'type' => 'ethernet', 'isExpensive' => null, 'isConstrained' => null]],
    ]);

    it('prefetches nothing on a metered, constrained, missing or unknown connection', function (?array $status) {
        prefetchSeries();
        Stored::network($status);

        expect(PrefetchNext::dispatchIfUnmetered('t6-pm', 't6-p1'))->toBeFalse();
        Queue::assertNotPushed(PrefetchNext::class);
    })->with([
        'cellular' => [Stored::cellular()],
        'personal hotspot' => [['connected' => true, 'type' => 'wifi', 'isExpensive' => true, 'isConstrained' => false]],
        'low data mode' => [['connected' => true, 'type' => 'wifi', 'isExpensive' => false, 'isConstrained' => true]],
        'not connected' => [['connected' => false, 'type' => 'wifi', 'isExpensive' => false, 'isConstrained' => false]],
        'connected unknown' => [['type' => 'wifi', 'isExpensive' => false, 'isConstrained' => false]],
        'no native bridge' => [null],
    ]);
});
