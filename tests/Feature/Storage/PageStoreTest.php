<?php

use App\Models\StoredChapter;
use App\Storage\PageStore;
use App\Storage\StorageBridge;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Tests\Support\Covers;
use Tests\Support\Pages;
use Tests\Support\Stored;

beforeEach(function () {
    $this->caches = Covers::useTempCaches();
    $this->pages = app(PageStore::class);
});

describe('begin', function () {
    it('creates the stored chapter and its manifest', function () {
        $stored = $this->pages->begin(Pages::chapter(), 'data-saver', 3);

        expect($stored->only(['chapter_id', 'tier', 'quality', 'status', 'pages_done', 'page_count', 'bytes']))->toBe([
            'chapter_id' => 'c1', 'tier' => 'cache', 'quality' => 'data-saver', 'status' => 'partial',
            'pages_done' => 0, 'page_count' => 3, 'bytes' => 0,
        ])->and($this->pages->manifest('c1'))->toBe([
            'version' => 1,
            'manga_id' => 'm1',
            'chapter_id' => 'c1',
            'number' => '12',
            'volume' => '2',
            'title' => 'The Witch',
            'group' => 'Blackhole Scan',
            'language' => 'es-la',
            'quality' => 'data-saver',
            'pages' => [null, null, null],
        ]);
    });

    it('keeps the quality a chapter started with', function () {
        $chapter = Pages::chapter();
        $this->pages->begin($chapter, 'data-saver', 3);
        $this->pages->put('c1', 0, Pages::image());

        $stored = $this->pages->begin($chapter, 'original', 3);

        expect($stored->quality)->toBe('data-saver')
            ->and($this->pages->manifest('c1')['quality'])->toBe('data-saver')
            ->and($this->pages->hasPage('c1', 0))->toBeTrue();
    });

    it('rejects an unknown quality or tier', function (string $quality, string $tier) {
        $this->pages->begin(Pages::chapter(), $quality, 3, $tier);
    })->with([['hd', 'cache'], ['original', 'icloud']])->throws(InvalidArgumentException::class);

    it('rejects a chapter id that is not a plain folder name', function () {
        $this->pages->begin(Pages::chapter('../escape'), 'original', 3);
    })->throws(InvalidArgumentException::class);
});

describe('put', function () {
    it('stores the page under its number, measures it and counts it', function () {
        $this->pages->begin(Pages::chapter(), 'data-saver', 3);
        $bytes = Pages::image(440, 1137);

        expect($this->pages->put('c1', 1, $bytes))->toBeTrue()
            ->and(file_get_contents("{$this->caches}/chapters/c1/002.jpg"))->toBe($bytes)
            ->and(glob("{$this->caches}/chapters/c1/*.part"))->toBe([])
            ->and($this->pages->manifest('c1')['pages'])->toBe([null, ['file' => '002.jpg', 'width' => 440, 'height' => 1137, 'bytes' => strlen($bytes)], null])
            ->and(StoredChapter::query()->find('c1')->only(['pages_done', 'bytes', 'status']))->toBe(['pages_done' => 1, 'bytes' => strlen($bytes), 'status' => 'partial']);
    });

    it('keeps the image type in the file name', function () {
        $this->pages->begin(Pages::chapter(), 'original', 3);

        $this->pages->put('c1', 0, Pages::image(type: 'png'));

        expect($this->pages->pathFor('c1', 0))->toBe('storage/cache/chapters/c1/001.png');
    });

    it('marks the chapter done with its last page', function () {
        $this->pages->begin(Pages::chapter(), 'data-saver', 3);

        foreach ([2, 0, 1] as $index) {
            $this->pages->put('c1', $index, Pages::image());
        }

        expect(StoredChapter::query()->find('c1')->status)->toBe('done')
            ->and($this->pages->has('c1'))->toBeTrue();
    });

    it('stores nothing that is not an image, is out of range or belongs to no started chapter', function (string $id, int $index, bool $image) {
        $this->pages->begin(Pages::chapter(), 'data-saver', 3);

        expect($this->pages->put($id, $index, $image ? Pages::image() : '<html>captive portal</html>'))->toBeFalse()
            ->and(glob("{$this->caches}/chapters/c1/*.jpg"))->toBe([])
            ->and(StoredChapter::query()->find('c1')->pages_done)->toBe(0);
    })->with([
        'not an image' => ['c1', 0, false],
        'index 3 of 3' => ['c1', 3, true],
        'negative index' => ['c1', -1, true],
        'not started' => ['c2', 0, true],
    ]);

    it('replaces a page stored again (no double count)', function () {
        $this->pages->begin(Pages::chapter(), 'data-saver', 3);
        $this->pages->put('c1', 0, Pages::image(4, 6, 'png'));
        $second = Pages::image(8, 12);

        $this->pages->put('c1', 0, $second);

        expect(glob("{$this->caches}/chapters/c1/001.*"))->toBe(["{$this->caches}/chapters/c1/001.jpg"])
            ->and(StoredChapter::query()->find('c1')->only(['pages_done', 'bytes']))->toBe(['pages_done' => 1, 'bytes' => strlen($second)]);
    });
});

describe('reading back', function () {
    it('gives no path until a page is on the phone', function () {
        $this->pages->begin(Pages::chapter(), 'data-saver', 3);

        expect($this->pages->pathFor('c1', 0))->toBeNull()
            ->and($this->pages->pathFor('nope', 0))->toBeNull()
            ->and($this->pages->hasPage('c1', 0))->toBeFalse()
            ->and($this->pages->has('c1'))->toBeFalse()
            ->and($this->pages->has('nope'))->toBeFalse();

        $this->pages->put('c1', 0, Pages::image());

        expect($this->pages->pathFor('c1', 0))->toBe('storage/cache/chapters/c1/001.jpg');
    });

    it('lists every page with its size, null while missing, in page order', function () {
        $this->pages->begin(Pages::chapter(), 'data-saver', 3);
        $this->pages->put('c1', 2, Pages::image(440, 1221));

        expect($this->pages->pages('c1'))->toBe([
            null,
            null,
            ['src' => 'storage/cache/chapters/c1/003.jpg', 'width' => 440, 'height' => 1221],
        ])->and($this->pages->missing('c1'))->toBe([0, 1])
            ->and($this->pages->pages('nope'))->toBe([]);
    });

    it('reads the manifest back from disk (a new PageStore sees the same chapter)', function () {
        $this->pages->begin(Pages::chapter(), 'original', 3);
        $this->pages->put('c1', 0, Pages::image(10, 20));
        $before = $this->pages->manifest('c1');

        $manifest = json_decode(file_get_contents("{$this->caches}/chapters/c1/manifest.json"), true);

        expect($manifest)->toBe($before)
            ->and(app(PageStore::class)->manifest('c1'))->toBe($before);
    });

    it('treats a purged page as not stored', function () {
        $this->pages->begin(Pages::chapter(), 'data-saver', 3);

        foreach ([0, 1, 2] as $index) {
            $this->pages->put('c1', $index, Pages::image());
        }

        unlink("{$this->caches}/chapters/c1/002.jpg");

        expect($this->pages->has('c1'))->toBeFalse()
            ->and($this->pages->hasPage('c1', 1))->toBeFalse()
            ->and($this->pages->pathFor('c1', 1))->toBeNull()
            ->and($this->pages->pages('c1')[1])->toBeNull()
            ->and($this->pages->missing('c1'))->toBe([1]);
    });

    it('treats a purged chapter folder as not stored, and begin() starts it over', function () {
        $chapter = Pages::chapter();
        $this->pages->begin($chapter, 'data-saver', 3);
        $this->pages->put('c1', 0, Pages::image());
        File::deleteDirectory("{$this->caches}/chapters/c1");

        expect($this->pages->has('c1'))->toBeFalse()
            ->and($this->pages->manifest('c1'))->toBeNull()
            ->and($this->pages->pages('c1'))->toBe([]);

        $this->pages->begin($chapter, 'original', 3);

        expect($this->pages->manifest('c1')['pages'])->toBe([null, null, null])
            ->and($this->pages->manifest('c1')['quality'])->toBe('data-saver')
            ->and(StoredChapter::query()->find('c1')->only(['pages_done', 'bytes', 'status']))->toBe(['pages_done' => 0, 'bytes' => 0, 'status' => 'partial']);
    });
});

describe('evict', function () {
    it('removes the folder and the stored chapter, nothing else', function () {
        $this->pages->begin(Pages::chapter('c1'), 'data-saver', 3);
        $this->pages->begin(Pages::chapter('c2'), 'data-saver', 3);
        $this->pages->put('c1', 0, Pages::image());
        $this->pages->put('c2', 0, Pages::image());

        $this->pages->evict('c1');

        expect(is_dir("{$this->caches}/chapters/c1"))->toBeFalse()
            ->and(StoredChapter::query()->find('c1'))->toBeNull()
            ->and($this->pages->pathFor('c2', 0))->not->toBeNull();
    });

    it('does nothing for an unknown or unsafe id', function (string $id) {
        $cover = Covers::store($this->caches, Covers::manga('m1'));
        File::ensureDirectoryExists("{$this->caches}/chapters");

        $this->pages->evict($id);

        expect(is_file($cover))->toBeTrue()->and(is_dir("{$this->caches}/chapters"))->toBeTrue();
    })->with(['nope', '..', '.', '']);

    it('ignores a stored_chapters row whose id is not a folder name (rows not made by begin)', function () {
        $cover = Covers::store($this->caches, Covers::manga('m1'));
        File::ensureDirectoryExists("{$this->caches}/chapters");
        StoredChapter::query()->create(['chapter_id' => '..', 'tier' => 'cache', 'quality' => 'original', 'status' => 'done']);

        $this->pages->evict('..');

        expect(is_file($cover))->toBeTrue()
            ->and(is_dir("{$this->caches}/chapters"))->toBeTrue()
            ->and($this->pages->manifest('..'))->toBeNull()
            ->and($this->pages->put('..', 0, Pages::image()))->toBeFalse();
    });
});

describe('usage', function () {
    it('counts chapter pages and covers in the cache tier', function () {
        expect($this->pages->usage())->toBe(0);

        $this->pages->begin(Pages::chapter(), 'data-saver', 3);
        $page = Pages::image(40, 60);
        $this->pages->put('c1', 0, $page);
        $cover = Covers::store($this->caches, Covers::manga('m1'));
        $manifest = filesize("{$this->caches}/chapters/c1/manifest.json");

        expect($this->pages->usage())->toBe(strlen($page) + filesize($cover) + $manifest);
    });
});

describe('local tier', function () {
    it('stores downloads under storage/app/public/downloads, shown through the storage link', function () {
        $storage = sys_get_temp_dir().'/readex-storage-'.bin2hex(random_bytes(4));
        app()->useStoragePath($storage);
        register_shutdown_function(fn () => File::deleteDirectory($storage));

        $this->pages->begin(Pages::chapter(), 'original', 3, 'local');
        $this->pages->put('c1', 0, Pages::image());

        expect(is_file("{$storage}/app/public/downloads/c1/001.jpg"))->toBeTrue()
            ->and($this->pages->pathFor('c1', 0))->toBe('storage/downloads/c1/001.jpg')
            ->and($this->pages->usage())->toBe(0);
    });
});

describe('promote and demote', function () {
    beforeEach(function () {
        $this->storage = Stored::useTempStorage();
    });

    it('moves a cached chapter to the downloads folder, keeping its quality, counts and manifest', function () {
        Stored::partial('t7-p1', 2, 3, quality: 'original');
        $before = StoredChapter::query()->find('t7-p1')->only(['quality', 'status', 'pages_done', 'page_count', 'bytes']);
        $manifest = $this->pages->manifest('t7-p1');

        expect($this->pages->promote('t7-p1'))->toBeTrue();

        expect(StoredChapter::query()->find('t7-p1')->tier)->toBe('local')
            ->and(StoredChapter::query()->find('t7-p1')->only(['quality', 'status', 'pages_done', 'page_count', 'bytes']))->toBe($before)
            ->and($this->pages->manifest('t7-p1'))->toBe($manifest)
            ->and(is_dir("{$this->caches}/chapters/t7-p1"))->toBeFalse()
            ->and(is_file("{$this->storage}/app/public/downloads/t7-p1/001.jpg"))->toBeTrue()
            ->and($this->pages->pathFor('t7-p1', 0))->toBe('storage/downloads/t7-p1/001.jpg')
            ->and($this->pages->missing('t7-p1'))->toBe([2]);
    });

    it('moves a download back to the cache tier, still readable', function () {
        Stored::chapter('t7-p1', 2);
        $this->pages->promote('t7-p1');

        expect($this->pages->demote('t7-p1'))->toBeTrue();

        expect(StoredChapter::query()->find('t7-p1')->tier)->toBe('cache')
            ->and(is_dir("{$this->storage}/app/public/downloads/t7-p1"))->toBeFalse()
            ->and(is_file("{$this->caches}/chapters/t7-p1/002.jpg"))->toBeTrue()
            ->and($this->pages->pathFor('t7-p1', 1))->toBe('storage/cache/chapters/t7-p1/002.jpg')
            ->and($this->pages->has('t7-p1'))->toBeTrue();
    });

    it('says false for a chapter that is not stored, and leaves a chapter already in the tier alone', function () {
        Stored::chapter('t7-p1', 2, tier: 'local');
        Stored::chapter('t7-p2', 2);

        expect($this->pages->promote('t7-none'))->toBeFalse()
            ->and($this->pages->demote('t7-none'))->toBeFalse()
            ->and($this->pages->promote('t7-p1'))->toBeTrue()
            ->and($this->pages->demote('t7-p2'))->toBeTrue()
            ->and($this->pages->pathFor('t7-p1', 0))->toBe('storage/downloads/t7-p1/001.jpg')
            ->and($this->pages->pathFor('t7-p2', 0))->toBe('storage/cache/chapters/t7-p2/001.jpg')
            ->and(StoredChapter::query()->find('t7-none'))->toBeNull();
    });

    it('keeps the downloads folder out of the iCloud backup', function () {
        $bridge = new class(sys_get_temp_dir().'/readex-covers-'.bin2hex(random_bytes(4))) implements StorageBridge
        {
            public array $excluded = [];

            public function __construct(private readonly string $root) {}

            public function preferredLanguage(): ?string
            {
                return null;
            }

            public function cachesPath(): string
            {
                return $this->root.'/caches';
            }

            public function excludeFromBackup(string $path): bool
            {
                $this->excluded[] = $path;

                return true;
            }

            public function timeZone(): string
            {
                return 'UTC';
            }
        };
        app()->instance(StorageBridge::class, $bridge);
        register_shutdown_function(fn () => File::deleteDirectory($bridge->cachesPath()));
        Stored::chapter('t7-p1', 1);
        expect($bridge->excluded)->toBe([]);

        app(PageStore::class)->promote('t7-p1');

        expect($bridge->excluded)->toBe(["{$this->storage}/app/public/downloads"])
            ->and(is_dir("{$this->storage}/app/public/downloads"))->toBeTrue();

        app(PageStore::class)->begin(Pages::chapter('t7-p2', ['pages' => 1]), 'data-saver', 1, 'cache');
        app(PageStore::class)->begin(Pages::chapter('t7-p3', ['pages' => 1]), 'data-saver', 1, 'local');

        expect($bridge->excluded)->toBe(["{$this->storage}/app/public/downloads", "{$this->storage}/app/public/downloads"]);
    });

    it('lands a page put during a promote in the downloads folder, with no folder left in the cache tier', function () {
        Stored::partial('t7-p1', 1, 3);
        $lock = Cache::lock('readex.pages.store.t7-p1', 30, 'other-runtime');
        expect($lock->get())->toBeTrue();
        Sleep::fake();
        $moved = false;
        Sleep::whenFakingSleep(function () use ($lock, &$moved) {
            if (! $moved) {
                $moved = true;
                $lock->release();
                app(PageStore::class)->promote('t7-p1');
            }
        });

        expect($this->pages->put('t7-p1', 1, Pages::image()))->toBeTrue();

        expect($moved)->toBeTrue()
            ->and(is_dir("{$this->caches}/chapters/t7-p1"))->toBeFalse()
            ->and(is_file("{$this->storage}/app/public/downloads/t7-p1/002.jpg"))->toBeTrue()
            ->and($this->pages->missing('t7-p1'))->toBe([2])
            ->and(StoredChapter::query()->find('t7-p1')->only(['tier', 'pages_done', 'status']))->toBe(['tier' => 'local', 'pages_done' => 2, 'status' => 'partial']);
    });

    it('waits for a page being written before it moves the folder', function () {
        Stored::partial('t7-p1', 1, 3);
        $lock = Cache::lock('readex.pages.store.t7-p1', 30, 'other-runtime');
        $lock->get();
        Sleep::fake();
        $written = false;
        Sleep::whenFakingSleep(function () use ($lock, &$written) {
            if (! $written) {
                $written = true;
                $lock->release();
                app(PageStore::class)->put('t7-p1', 1, Pages::image());
            }
        });

        $this->pages->promote('t7-p1');

        expect($written)->toBeTrue()
            ->and(is_dir("{$this->caches}/chapters/t7-p1"))->toBeFalse()
            ->and($this->pages->missing('t7-p1'))->toBe([2])
            ->and(StoredChapter::query()->find('t7-p1')->pages_done)->toBe(2);
    });
});
