<?php

use App\Downloads\Downloads;
use App\Jobs\DownloadChapter;
use App\Jobs\EnforceCacheCap;
use App\Models\Collection as LibraryEntry;
use App\Models\ReadingProgress;
use App\Models\StoredChapter;
use App\Repositories\LibraryRepository;
use App\Storage\ImageCache;
use App\Storage\OpenChapter;
use App\Storage\PageStore;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\Covers;
use Tests\Support\Stored;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 02:30:00', 'UTC'));
    $this->caches = Covers::useTempCaches();
    $this->store = app(PageStore::class);
});

function capAt(int $bytes): void
{
    config(['readex.cache.cap_bytes' => $bytes]);
}

/** The cache fits this many chapters of the (equally long) ids stored so far, plus `$spare` bytes. */
function capFits(float $chapters, int $spare = 0): void
{
    capAt((int) (app(PageStore::class)->usage() / StoredChapter::query()->count() * $chapters) + $spare);
}

function enforceCap(): void
{
    (new EnforceCacheCap)->handle(app(ImageCache::class));
}

/** @return list<string> */
function cachedIds(): array
{
    return StoredChapter::query()->orderBy('chapter_id')->pluck('chapter_id')->all();
}

it('caps the cache at 1 GB, decimal, and prefetches 2 chapters', function () {
    expect(config('readex.cache.cap_bytes'))->toBe(1_000_000_000)
        ->and(config('readex.cache.prefetch_chapters'))->toBe(2);
});

it('runs after a cache write, one at a time', function () {
    expect(new EnforceCacheCap)->toBeInstanceOf(ShouldQueue::class)->toBeInstanceOf(ShouldBeUnique::class);
});

describe('EnforceCacheCap', function () {
    it('deletes the chapter read longest ago first, folder and row, and stops once under the cap', function () {
        Stored::chapter('t6-c', openedAt: '2026-10-09 10:00:00');
        Stored::chapter('t6-a', openedAt: '2026-10-01 10:00:00');
        Stored::chapter('t6-b', openedAt: '2026-10-05 10:00:00');
        capFits(2.5);

        enforceCap();

        expect(cachedIds())->toBe(['t6-b', 't6-c'])
            ->and(is_dir("{$this->caches}/chapters/t6-a"))->toBeFalse()
            ->and($this->store->has('t6-b'))->toBeTrue()
            ->and($this->store->has('t6-c'))->toBeTrue();
    });

    it('keeps deleting until the cache fits', function () {
        Stored::chapter('t6-c', openedAt: '2026-10-09 10:00:00');
        Stored::chapter('t6-a', openedAt: '2026-10-01 10:00:00');
        Stored::chapter('t6-b', openedAt: '2026-10-05 10:00:00');
        capFits(1.5);

        enforceCap();

        expect(cachedIds())->toBe(['t6-c']);
    });

    it('goes by when a chapter was last opened, not by how it was stored', function () {
        Stored::chapter('t6-a', openedAt: '2026-10-20 10:00:00');
        Stored::chapter('t6-b', openedAt: '2026-10-02 10:00:00');
        capFits(1.5);

        enforceCap();

        expect(cachedIds())->toBe(['t6-a']);
    });

    it('deletes prefetched chapters nobody opened after every opened one', function () {
        Stored::chapter('t6-a', openedAt: '2026-10-01 10:00:00');
        Stored::chapter('t6-n');
        Stored::chapter('t6-b', openedAt: '2026-10-05 10:00:00');
        capFits(2.5);

        enforceCap();

        expect(cachedIds())->toBe(['t6-b', 't6-n']);

        capFits(1.5);
        enforceCap();

        expect(cachedIds())->toBe(['t6-n']);
    });

    it('never deletes the open chapter, even when it was read longest ago, and stops when nothing else is left', function () {
        Stored::chapter('t6-open', openedAt: '2026-09-01 10:00:00');
        Stored::chapter('t6-b', openedAt: '2026-10-05 10:00:00');
        Stored::chapter('t6-c');
        app(OpenChapter::class)->set('t6-open');
        capAt(0);

        enforceCap();

        expect(cachedIds())->toBe(['t6-open'])
            ->and($this->store->has('t6-open'))->toBeTrue();
    });

    it('only deletes the cache tier: downloads stay, however old', function () {
        $storage = sys_get_temp_dir().'/readex-storage-'.bin2hex(random_bytes(4));
        app()->useStoragePath($storage);
        register_shutdown_function(fn () => File::deleteDirectory($storage));
        Stored::chapter('t6-local', openedAt: '2020-01-01 00:00:00', tier: 'local');
        Stored::chapter('t6-b', openedAt: '2026-10-05 10:00:00');
        capAt(0);

        enforceCap();

        expect(cachedIds())->toBe(['t6-local'])
            ->and($this->store->has('t6-local'))->toBeTrue();
    });

    it('counts the covers, and never deletes them', function () {
        Stored::chapter('t6-a', openedAt: '2026-10-01 10:00:00');
        Stored::chapter('t6-b', openedAt: '2026-10-05 10:00:00');
        capFits(2);
        $cover = Covers::store($this->caches, Covers::manga('t6-cm'));

        enforceCap();

        expect(cachedIds())->toBe(['t6-b'])
            ->and(is_file($cover))->toBeTrue();

        capAt(0);
        enforceCap();

        expect(cachedIds())->toBe([])
            ->and(is_file($cover))->toBeTrue();
    });

    it('leaves a cache exactly at the cap alone', function () {
        Stored::chapter('t6-a', openedAt: '2026-10-01 10:00:00');
        Stored::chapter('t6-b');
        capAt(app(PageStore::class)->usage());

        enforceCap();

        expect(cachedIds())->toBe(['t6-a', 't6-b']);
    });

    it('leaves a cache under the cap alone', function () {
        Stored::chapter('t6-a', openedAt: '2026-10-01 10:00:00');
        Stored::chapter('t6-b');
        capFits(2);

        enforceCap();

        expect(cachedIds())->toBe(['t6-a', 't6-b']);
    });
});

describe('OpenChapter', function () {
    it('names one chapter, for every runtime that reads the cache', function () {
        $open = app(OpenChapter::class);

        expect($open->id())->toBeNull();

        $open->set('t6-a');

        expect(app(OpenChapter::class)->id())->toBe('t6-a');

        $open->clear('t6-b');
        expect($open->id())->toBe('t6-a');

        $open->clear('t6-a');
        expect($open->id())->toBeNull();
    });
});

describe('Clear image cache', function () {
    it('deletes every cached chapter and cover thumbnail but the open chapter', function () {
        Stored::chapter('t6-open', openedAt: '2026-09-01 10:00:00');
        $open = app(ImageCache::class)->usage();
        $cover = Covers::store($this->caches, Covers::manga('t6-cm'));
        Stored::chapter('t6-a', openedAt: '2026-10-01 10:00:00');
        Stored::chapter('t6-n');
        app(OpenChapter::class)->set('t6-open');

        app(ImageCache::class)->clear();

        expect(cachedIds())->toBe(['t6-open'])
            ->and($this->store->has('t6-open'))->toBeTrue()
            ->and(is_file($cover))->toBeFalse()
            ->and(app(ImageCache::class)->usage())->toBe($open);
    });

    it('keeps reading progress, the Library and downloads', function () {
        $storage = sys_get_temp_dir().'/readex-storage-'.bin2hex(random_bytes(4));
        app()->useStoragePath($storage);
        register_shutdown_function(fn () => File::deleteDirectory($storage));
        Stored::chapter('t6-a');
        Stored::chapter('t6-local', tier: 'local');
        app(LibraryRepository::class)->saveProgress('t6-a', 1, 2);
        LibraryEntry::query()->firstOrCreate(['manga_id' => 't6-m'], ['favorite' => true]);

        app(ImageCache::class)->clear();

        expect(cachedIds())->toBe(['t6-local'])
            ->and($this->store->has('t6-local'))->toBeTrue()
            ->and(ReadingProgress::query()->find('t6-a')->page)->toBe(1)
            ->and(LibraryEntry::query()->count())->toBe(1);
    });

    it('is fine on an empty cache', function () {
        app(ImageCache::class)->clear();

        expect(app(ImageCache::class)->usage())->toBe(0);
    });
});

describe('downloaded chapters (Review Focus 8)', function () {
    it('moves a chapter read yesterday to the downloads without asking for a page again; the cap and Clear image cache leave it alone', function () {
        Stored::useTempStorage();
        Queue::fake();
        Http::fake(fn () => throw new RuntimeException('no network'));
        Stored::chapter('t7-ic-read', 3, openedAt: '2026-10-23 20:00:00');
        Stored::chapter('t7-ic-new', 2, openedAt: '2026-10-24 01:00:00');

        app(Downloads::class)->download('t7-ic-read');
        capAt(0);
        enforceCap();

        expect(cachedIds())->toBe(['t7-ic-read'])
            ->and(StoredChapter::query()->find('t7-ic-read')->tier)->toBe('local');

        $this->store->forgetEvicted('t7-ic-new');
        Stored::chapter('t7-ic-new', 2);
        app(ImageCache::class)->clear();

        expect(cachedIds())->toBe(['t7-ic-read'])
            ->and($this->store->has('t7-ic-read'))->toBeTrue()
            ->and($this->store->pathFor('t7-ic-read', 2))->toBe('storage/downloads/t7-ic-read/003.jpg')
            ->and(app(ImageCache::class)->usage())->toBe(0);
        Http::assertNothingSent();
        Queue::assertNotPushed(DownloadChapter::class);
    });

    it('keeps a chapter another runtime downloads while the cap or Clear image cache is going through the list', function (string $run) {
        $storage = Stored::useTempStorage();
        Stored::chapter('t7-ic-old', 2, openedAt: '2026-10-20 10:00:00');
        Stored::chapter('t7-ic-moved', 2, openedAt: '2026-10-21 10:00:00');
        capAt(0);
        $lock = Cache::lock('readex.pages.store.t7-ic-moved', 30, 'other-runtime');
        expect($lock->get())->toBeTrue();
        Sleep::fake();
        $promoted = false;
        Sleep::whenFakingSleep(function () use ($lock, &$promoted) {
            if (! $promoted) {
                $promoted = true;
                $lock->release();
                app(PageStore::class)->promote('t7-ic-moved');
            }
        });

        $run === 'cap' ? enforceCap() : app(ImageCache::class)->clear();

        expect($promoted)->toBeTrue()
            ->and(cachedIds())->toBe(['t7-ic-moved'])
            ->and(StoredChapter::query()->find('t7-ic-moved')->tier)->toBe('local')
            ->and(is_file("{$storage}/app/public/downloads/t7-ic-moved/002.jpg"))->toBeTrue()
            ->and($this->store->has('t7-ic-moved'))->toBeTrue();
    })->with(['cap', 'clear']);
});
