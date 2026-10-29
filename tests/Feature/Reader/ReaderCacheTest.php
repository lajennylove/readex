<?php

use App\Adapters\Http\RateLimit\Priority;
use App\Jobs\DownloadChapter;
use App\Jobs\EnforceCacheCap;
use App\Jobs\PrefetchNext;
use App\Models\Chapter;
use App\Models\StoredChapter;
use App\Pages\PageFetcher;
use App\Repositories\LibraryRepository;
use App\Storage\ImageCache;
use App\Storage\OpenChapter;
use App\Storage\PageStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Native\Mobile\AsyncTask;
use Native\Mobile\Testing\Native;
use Tests\Support\Covers;
use Tests\Support\Pages;
use Tests\Support\Stored;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 02:30:00', 'UTC'));
    Sleep::fake(syncWithCarbon: true);
    $this->caches = Covers::useTempCaches();
    $this->async = AsyncTask::fake();
    Queue::fake();
});

afterEach(fn () => AsyncTask::clearFake());

/** Chapters 12, 13, 14 of series t6-m, none on the phone. */
function readerSeries(): void
{
    foreach (['t6-r1' => '12', 't6-r2' => '13', 't6-r3' => '14'] as $id => $number) {
        Pages::chapter($id, ['manga_id' => 't6-m', 'number' => $number, 'pages' => 2]);
    }
}

it('marks the chapter it shows as the open one, and clears it when it closes', function () {
    Stored::chapter('t6-r1');
    $open = app(OpenChapter::class);

    $screen = Native::visit('/home/read/t6-r1');

    expect($open->id())->toBe('t6-r1');

    $screen->instance()->unmount();

    expect($open->id())->toBeNull();
});

it('leaves the marker of the chapter that replaced it alone when it closes', function () {
    Stored::chapter('t6-r1');
    Stored::chapter('t6-r2', attributes: ['number' => '13']);
    $open = app(OpenChapter::class);

    $first = Native::visit('/home/read/t6-r1');
    Native::visit('/home/read/t6-r2');
    $first->instance()->unmount();

    expect($open->id())->toBe('t6-r2');
});

it('names the next chapter as soon as it switches to it', function () {
    Stored::chapter('t6-r1');
    Pages::chapter('t6-r2', ['manga_id' => 't6-m', 'number' => '13', 'pages' => 2]);

    $screen = Native::visit('/home/read/t6-r1')->call('nextChapter')->assertReplacedWith('/home/read/t6-r2');

    expect(app(OpenChapter::class)->id())->toBe('t6-r2');
    $screen->instance()->unmount();
    expect(app(OpenChapter::class)->id())->toBe('t6-r2');
});

describe('prefetch', function () {
    it('queues PrefetchNext once the chapter is on the phone and the connection is unmetered, once per opened chapter', function () {
        readerSeries();
        Stored::chapter('t6-r1');
        Stored::network(Stored::wifi());

        $screen = Native::visit('/home/read/t6-r1');
        Queue::assertNotPushed(PrefetchNext::class);

        $screen->firePoll('checkPages')->firePoll('checkPages');

        Queue::assertPushed(PrefetchNext::class, 1);
        Queue::assertPushed(PrefetchNext::class, fn (PrefetchNext $job) => $job->mangaId === 't6-m' && $job->fromChapterId === 't6-r1');
    });

    it('waits for the last page, however connected the phone is', function () {
        readerSeries();
        Stored::network(Stored::wifi());
        $store = app(PageStore::class);
        $store->begin(Chapter::query()->find('t6-r1'), 'data-saver', 2);
        $store->put('t6-r1', 0, Pages::image());
        cache()->put('readex.pages.running.t6-r1', true, 60);

        $screen = Native::visit('/home/read/t6-r1')->firePoll('checkPages');
        Queue::assertNotPushed(PrefetchNext::class);

        $store->put('t6-r1', 1, Pages::image());
        $screen->firePoll('checkPages');

        Queue::assertPushed(PrefetchNext::class, 1);
    });

    it('prefetches nothing on cellular, then once the phone is back on Wi-Fi, asked again after 15 s', function () {
        readerSeries();
        Stored::chapter('t6-r1');
        Stored::network(Stored::cellular());

        $screen = Native::visit('/home/read/t6-r1')->firePoll('checkPages')->firePoll('checkPages');
        Queue::assertNotPushed(PrefetchNext::class);

        Stored::network(Stored::wifi());
        $this->travel(15)->seconds();
        $screen->firePoll('checkPages');

        Queue::assertPushed(PrefetchNext::class, 1);
    });

    it('asks the phone about its connection at most once every 15 s while it is metered, not on every 1 s poll', function () {
        readerSeries();
        Stored::chapter('t6-r1');
        $bridge = Native::fakeBridge()->respondTo('Network.Status', Stored::cellular());

        $screen = Native::visit('/home/read/t6-r1')->firePoll('checkPages');
        foreach (range(1, 14) as $second) {
            $this->travel(1)->seconds();
            $screen->firePoll('checkPages');
        }

        expect($bridge->callsTo('Network.Status'))->toHaveCount(1);

        $this->travel(1)->seconds();
        $screen->firePoll('checkPages');

        expect($bridge->callsTo('Network.Status'))->toHaveCount(2);
        Queue::assertNotPushed(PrefetchNext::class);
    });

    it('never prefetches for a licensed chapter', function () {
        Pages::chapter('t6-r1', ['manga_id' => 't6-m', 'pages' => 0, 'external_url' => 'https://mangaplus.shueisha.co.jp/viewer/12']);
        Stored::network(Stored::wifi());

        Native::visit('/home/read/t6-r1')->firePoll('checkPages');

        Queue::assertNotPushed(PrefetchNext::class);
    });

    it('never prefetches when the phone says nothing about its connection (Network::status() is null)', function () {
        readerSeries();
        Stored::chapter('t6-r1');
        Stored::network(null);

        Native::visit('/home/read/t6-r1')->firePoll('checkPages')->firePoll('checkPages');

        Queue::assertNotPushed(PrefetchNext::class);
    });

    it('leaves PrefetchNext to its own job: it queues the next two chapters at prefetch priority', function () {
        readerSeries();
        Stored::chapter('t6-r1');
        Stored::network(Stored::wifi());
        Native::visit('/home/read/t6-r1')->firePoll('checkPages');

        Queue::pushed(PrefetchNext::class)->first()->handle(app(LibraryRepository::class), app(PageStore::class));

        expect(Queue::pushed(DownloadChapter::class)->map(fn (DownloadChapter $job) => [$job->chapterId, $job->tier, $job->priority])->values()->all())
            ->toBe([['t6-r2', 'cache', Priority::Prefetch], ['t6-r3', 'cache', Priority::Prefetch]]);
    });
});

it('on 5G prefetches nothing, and opening the next chapter still loads it (Review Focus 7)', function () {
    Pages::fake(count: 2);
    readerSeries();
    Stored::network(Stored::cellular());

    $screen = Native::visit('/home/read/t6-r1');
    expect(app(PageStore::class)->has('t6-r1'))->toBeTrue();

    $screen->firePoll('checkPages')->call('nextChapter')->assertReplacedWith('/home/read/t6-r2');
    Native::visit('/home/read/t6-r2')->firePoll('checkPages');

    expect(app(PageStore::class)->has('t6-r2'))->toBeTrue()
        ->and(Pages::requested())->toBe(['a.source.test/data-saver/h1/p1.jpg', 'a.source.test/data-saver/h1/p2.jpg', 'a.source.test/data-saver/h1/p1.jpg', 'a.source.test/data-saver/h1/p2.jpg']);
    Queue::assertNotPushed(PrefetchNext::class);
    Queue::assertNotPushed(DownloadChapter::class);
    expect(app(PageStore::class)->manifest('t6-r3'))->toBeNull();
});

it('with the cache over its cap while reading, drops the oldest-read chapters and keeps the open one, its pages and the covers (Review Focus 6)', function () {
    Pages::fake(count: 2);
    Stored::chapter('t6-r1');
    Stored::chapter('t6-o1', openedAt: '2026-10-01 10:00:00');
    Stored::chapter('t6-o2', openedAt: '2026-10-10 10:00:00');
    Pages::chapter('t6-n1', ['manga_id' => 't6-m', 'number' => '20', 'pages' => 2]);
    $chapter = app(ImageCache::class)->usage() / 3;
    $cover = Covers::store($this->caches, Covers::manga('t6-cm'));
    config(['readex.cache.cap_bytes' => (int) ($chapter * 2) + filesize($cover) + 20]);

    Native::visit('/home/read/t6-r1');
    StoredChapter::query()->whereKey('t6-r1')->update(['last_opened_at' => '2026-09-01 10:00:00']);

    (new DownloadChapter('t6-n1'))->withFakeQueueInteractions()->handle(app(PageFetcher::class));
    Queue::assertPushed(EnforceCacheCap::class, 1);
    Queue::pushed(EnforceCacheCap::class)->first()->handle(app(ImageCache::class));

    expect(StoredChapter::query()->orderBy('chapter_id')->pluck('chapter_id')->all())->toBe(['t6-n1', 't6-r1'])
        ->and(app(PageStore::class)->has('t6-r1'))->toBeTrue()
        ->and(is_file($cover))->toBeTrue();
});
