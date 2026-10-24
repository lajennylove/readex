<?php

use App\Downloads\Downloads;
use App\Downloads\DownloadState;
use App\Jobs\DownloadChapter;
use App\MangaDex\RateLimit\Priority;
use App\Models\Collection as LibraryEntry;
use App\Models\Download;
use App\Models\ReadingProgress;
use App\Models\StoredChapter;
use App\Pages\PageFetcher;
use App\Repositories\LibraryRepository;
use App\Repositories\SettingsRepository;
use App\Storage\PageStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\Covers;
use Tests\Support\Pages;
use Tests\Support\Stored;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 03:00:00', 'UTC'));
    Sleep::fake(syncWithCarbon: true);
    $this->caches = Covers::useTempCaches();
    $this->storage = Stored::useTempStorage();
    Queue::fake();
    $this->store = app(PageStore::class);
    $this->downloads = app(Downloads::class);
});

/** Run this DownloadChapter job once (one slice), as the queue worker would. */
function t7Run(DownloadChapter $job): DownloadChapter
{
    $job = $job->withFakeQueueInteractions();
    $job->handle(app(PageFetcher::class));

    return $job;
}

/** The DownloadChapter jobs queued so far, as "id tier priority". */
function t7Queued(): array
{
    return Queue::pushed(DownloadChapter::class)->map(fn (DownloadChapter $j) => "{$j->chapterId} {$j->tier} {$j->priority->value}")->values()->all();
}

function t7Chapter(string $id, array $attributes = []): void
{
    Pages::chapter($id, ['manga_id' => 't7-m', 'pages' => 4, ...$attributes]);
}

it('downloads a chapter that is all in the cache by moving it: no page asked for, no job, Downloaded at once', function () {
    Http::fake(fn () => throw new RuntimeException('no network'));
    Stored::chapter('t7-full', 3);

    $this->downloads->download('t7-full');

    expect(StoredChapter::query()->find('t7-full')->tier)->toBe('local')
        ->and($this->store->pathFor('t7-full', 0))->toBe('storage/downloads/t7-full/001.jpg')
        ->and($this->downloads->status('t7-full')->state)->toBe(DownloadState::Downloaded)
        ->and($this->downloads->isQueued('t7-full'))->toBeFalse();
    Http::assertNothingSent();
    Queue::assertNotPushed(DownloadChapter::class);
});

it('moves a chapter partly in the cache and finishes the missing pages in the quality it started with', function () {
    Pages::fake(count: 4);
    Stored::partial('t7-half', 2, 4);
    app(SettingsRepository::class)->setImageQuality('original');

    $this->downloads->download('t7-half');

    expect(StoredChapter::query()->find('t7-half')->tier)->toBe('local')
        ->and(t7Queued())->toBe(['t7-half local prefetch'])
        ->and($this->downloads->status('t7-half')->caption())->toBe('Downloading 50%');

    t7Run(Queue::pushed(DownloadChapter::class)->first())->assertNotReleased();

    expect(Pages::requested())->toBe(['a.mangadex.network/data-saver/h1/p3.jpg', 'a.mangadex.network/data-saver/h1/p4.jpg'])
        ->and($this->store->pathFor('t7-half', 3))->toBe('storage/downloads/t7-half/004.jpg')
        ->and(StoredChapter::query()->find('t7-half')->only(['tier', 'quality', 'status']))->toBe(['tier' => 'local', 'quality' => 'data-saver', 'status' => 'done'])
        ->and(is_dir("{$this->caches}/chapters/t7-half"))->toBeFalse()
        ->and($this->downloads->status('t7-half')->state)->toBe(DownloadState::Downloaded)
        ->and($this->downloads->isQueued('t7-half'))->toBeFalse();
});

it('queues a chapter not on the phone, which begins in the downloads folder', function () {
    Pages::fake(count: 2);
    t7Chapter('t7-new', ['pages' => 2]);

    $this->downloads->download('t7-new');

    expect(t7Queued())->toBe(['t7-new local prefetch'])
        ->and(StoredChapter::query()->find('t7-new'))->toBeNull()
        ->and($this->downloads->status('t7-new')->caption())->toBe('Downloading 0%');

    t7Run(Queue::pushed(DownloadChapter::class)->first());

    expect($this->store->pathFor('t7-new', 1))->toBe('storage/downloads/t7-new/002.jpg')
        ->and(is_dir("{$this->caches}/chapters/t7-new"))->toBeFalse()
        ->and($this->downloads->status('t7-new')->state)->toBe(DownloadState::Downloaded);
});

it('puts the series in the Library with the chapter\'s group as the preferred one, on any connection', function () {
    Stored::network(Stored::cellular());
    t7Chapter('t7-lib', ['group_id' => 't7-g']);

    $this->downloads->download('t7-lib');

    expect(LibraryEntry::query()->find('t7-m')?->preferred_group_id)->toBe('t7-g')
        ->and(t7Queued())->toBe(['t7-lib local prefetch']);
});

it('does nothing for a licensed chapter, a chapter already downloaded, one already queued or one that is gone', function () {
    t7Chapter('t7-ext', ['external_url' => 'https://mangaplus.shueisha.co.jp/viewer/7']);
    Stored::chapter('t7-done', 2, tier: 'local');
    t7Chapter('t7-q');
    $this->downloads->download('t7-q');
    Queue::fake();

    foreach (['t7-ext', 't7-done', 't7-q', 't7-gone'] as $id) {
        $this->downloads->download($id);
    }

    Queue::assertNotPushed(DownloadChapter::class);
    expect(Download::query()->pluck('chapter_id')->all())->toBe(['t7-q'])
        ->and(LibraryEntry::query()->count())->toBe(1);
});

it('ends with the chapter downloaded when a cache job of the reader already holds its unique lock (dispatch dropped)', function (int $stored) {
    Pages::fake(count: 4);
    t7Chapter('t7-lock');
    if ($stored > 0) {
        Stored::partial('t7-lock', $stored, 4);
    }
    DownloadChapter::dispatch('t7-lock', 'cache', Priority::Interactive);

    $this->downloads->download('t7-lock');

    expect(t7Queued())->toBe(['t7-lock cache interactive']);

    t7Run(Queue::pushed(DownloadChapter::class)->first());

    expect(StoredChapter::query()->find('t7-lock')->only(['tier', 'status']))->toBe(['tier' => 'local', 'status' => 'done'])
        ->and(is_dir("{$this->caches}/chapters/t7-lock"))->toBeFalse()
        ->and($this->downloads->isQueued('t7-lock'))->toBeFalse()
        ->and(Download::query()->find('t7-lock')->finished_at)->not->toBeNull()
        ->and($this->downloads->status('t7-lock')->state)->toBe(DownloadState::Downloaded);
})->with(['not begun yet' => 0, 'partly read' => 1]);

it('ends with the chapter downloaded when Download is tapped while the reader\'s cache job looks up the chapter', function () {
    Pages::fake(count: 4);
    $tapped = false;
    Http::fake(['https://api.mangadex.org/at-home/server/*' => function () use (&$tapped) {
        if (! $tapped) {
            $tapped = true;
            app(Downloads::class)->download('t7-mid');
        }
    }]);
    t7Chapter('t7-mid');

    t7Run(new DownloadChapter('t7-mid', 'cache', Priority::Interactive))->assertNotReleased();

    expect($tapped)->toBeTrue()
        ->and(StoredChapter::query()->find('t7-mid')->only(['tier', 'status']))->toBe(['tier' => 'local', 'status' => 'done'])
        ->and(is_dir("{$this->caches}/chapters/t7-mid"))->toBeFalse()
        ->and($this->downloads->isQueued('t7-mid'))->toBeFalse()
        ->and($this->downloads->status('t7-mid')->state)->toBe(DownloadState::Downloaded);
});

it('finishes the download when the reader\'s own fetch began the chapter in the cache meanwhile', function () {
    Pages::fake(count: 2);
    t7Chapter('t7-race', ['pages' => 2]);
    $this->downloads->download('t7-race');
    Stored::chapter('t7-race', 2);

    t7Run(Queue::pushed(DownloadChapter::class)->first());

    expect(StoredChapter::query()->find('t7-race')->tier)->toBe('local')
        ->and($this->downloads->status('t7-race')->state)->toBe(DownloadState::Downloaded);
});

describe('Stop download', function () {
    it('stops the running job at the next page; the pages stored stay in the downloads, partial', function () {
        $images = 0;
        Pages::fake(count: 4, image: function () use (&$images) {
            if (++$images === 2) {
                app(Downloads::class)->stop('t7-stop');
            }

            return Http::response(Pages::image());
        });
        t7Chapter('t7-stop');
        $this->downloads->download('t7-stop');

        t7Run(Queue::pushed(DownloadChapter::class)->first())->assertNotReleased();

        expect($images)->toBe(2)
            ->and($this->store->missing('t7-stop'))->toBe([2, 3])
            ->and(StoredChapter::query()->find('t7-stop')->only(['tier', 'status']))->toBe(['tier' => 'local', 'status' => 'partial'])
            ->and($this->downloads->isQueued('t7-stop'))->toBeFalse()
            ->and($this->downloads->status('t7-stop')->caption())->toBe('Download stopped · 50%');
    });

    it('stops the reader\'s cache job of a queued chapter at the next page too, its pages in the downloads', function (int $stored) {
        $images = 0;
        Pages::fake(count: 4, image: function () use (&$images) {
            if (++$images === 1) {
                app(Downloads::class)->stop('t7-stop3');
            }

            return Http::response(Pages::image());
        });
        t7Chapter('t7-stop3');
        if ($stored > 0) {
            Stored::partial('t7-stop3', $stored, 4);
        }
        DownloadChapter::dispatch('t7-stop3', 'cache', Priority::Interactive);
        $this->downloads->download('t7-stop3');

        t7Run(Queue::pushed(DownloadChapter::class)->first())->assertNotReleased();

        expect($images)->toBe(1)
            ->and(StoredChapter::query()->find('t7-stop3')->only(['tier', 'status']))->toBe(['tier' => 'local', 'status' => 'partial'])
            ->and(is_dir("{$this->caches}/chapters/t7-stop3"))->toBeFalse()
            ->and($this->downloads->status('t7-stop3')->caption())->toBe('Download stopped · '.(($stored + 1) * 25).'%');
    })->with(['not begun yet' => 0, 'partly read' => 1]);

    it('moves a chapter the reader began in the cache after the Download before it fetches the rest', function () {
        $images = 0;
        Pages::fake(count: 4, image: function () use (&$images) {
            if (++$images === 1) {
                app(Downloads::class)->stop('t7-race2');
            }

            return Http::response(Pages::image());
        });
        t7Chapter('t7-race2');
        $this->downloads->download('t7-race2');
        Stored::partial('t7-race2', 1, 4);

        t7Run(Queue::pushed(DownloadChapter::class)->first())->assertNotReleased();

        expect(StoredChapter::query()->find('t7-race2')->only(['tier', 'status']))->toBe(['tier' => 'local', 'status' => 'partial'])
            ->and(is_dir("{$this->caches}/chapters/t7-race2"))->toBeFalse()
            ->and($this->downloads->status('t7-race2')->caption())->toBe('Download stopped · 50%');
    });

    it('leaves a download stopped and then removed in the cache when its old job runs', function () {
        Pages::fake(count: 4);
        Stored::partial('t7-stale', 2, 4);
        $this->downloads->download('t7-stale');
        $this->downloads->stop('t7-stale');
        $this->downloads->remove('t7-stale');

        t7Run(Queue::pushed(DownloadChapter::class)->first())->assertNotReleased();

        expect(StoredChapter::query()->find('t7-stale')->tier)->toBe('cache')
            ->and(is_dir("{$this->storage}/app/public/downloads/t7-stale"))->toBeFalse()
            ->and(Pages::requested())->toBe([]);
    });

    it('lets a job released before the stop end without asking for a page', function () {
        Pages::fake(count: 4);
        t7Chapter('t7-stop2');
        $this->downloads->download('t7-stop2');
        $this->downloads->stop('t7-stop2');

        t7Run(Queue::pushed(DownloadChapter::class)->first())->assertNotReleased();

        expect(Http::recorded())->toHaveCount(0)
            ->and(StoredChapter::query()->find('t7-stop2'))->toBeNull()
            ->and($this->downloads->status('t7-stop2')->state)->toBe(DownloadState::None);
    });

    it('never stops a reader or prefetch job for a chapter that is not in the download queue', function (Priority $priority) {
        Pages::fake(count: 3);
        t7Chapter('t7-reader', ['pages' => 3]);

        t7Run(new DownloadChapter('t7-reader', 'cache', $priority))->assertNotReleased();

        expect($this->store->has('t7-reader'))->toBeTrue()
            ->and(StoredChapter::query()->find('t7-reader')->tier)->toBe('cache')
            ->and(Download::query()->count())->toBe(0);
    })->with([Priority::Interactive, Priority::Prefetch]);

    it('can be resumed: Download again finishes the missing pages', function () {
        Pages::fake(count: 4);
        Stored::partial('t7-again', 2, 4, tier: 'local');
        expect($this->downloads->status('t7-again')->caption())->toBe('Download stopped · 50%');

        $this->downloads->download('t7-again');
        t7Run(Queue::pushed(DownloadChapter::class)->first());

        expect(Pages::requested())->toHaveCount(2)
            ->and($this->downloads->status('t7-again')->state)->toBe(DownloadState::Downloaded);
    });
});

describe('Remove download', function () {
    it('moves the chapter back to the cache tier: still readable, progress untouched', function () {
        Stored::chapter('t7-rm', 2, tier: 'local');
        app(LibraryRepository::class)->saveProgress('t7-rm', 1, 2);

        $this->downloads->remove('t7-rm');

        expect(StoredChapter::query()->find('t7-rm')->tier)->toBe('cache')
            ->and(is_file("{$this->caches}/chapters/t7-rm/002.jpg"))->toBeTrue()
            ->and($this->store->has('t7-rm'))->toBeTrue()
            ->and(ReadingProgress::query()->find('t7-rm')->only(['page', 'page_count']))->toBe(['page' => 1, 'page_count' => 2])
            ->and($this->downloads->status('t7-rm')->state)->toBe(DownloadState::None);
    });

    it('is not done while the chapter is queued or downloading', function () {
        Stored::partial('t7-rm2', 1, 4);
        $this->downloads->download('t7-rm2');

        $this->downloads->remove('t7-rm2');

        expect(StoredChapter::query()->find('t7-rm2')->tier)->toBe('local')
            ->and($this->downloads->isQueued('t7-rm2'))->toBeTrue();
    });
});

describe('status', function () {
    it('reads every row\'s state in one query', function () {
        t7Chapter('t7-s-none');
        Stored::chapter('t7-s-cached', 2);
        Stored::chapter('t7-s-done', 2, tier: 'local');
        Stored::partial('t7-s-stop', 1, 3, tier: 'local');
        Stored::partial('t7-s-run', 1, 4);
        $this->downloads->download('t7-s-run');
        t7Chapter('t7-s-feed', ['pages' => 20]);
        $this->downloads->download('t7-s-feed');
        t7Chapter('t7-s-zero', ['pages' => 0]);
        $this->downloads->download('t7-s-zero');
        Download::query()->create(['chapter_id' => 't7-s-wait', 'manga_id' => 't7-m', 'batch' => 'b', 'position' => 1, 'priority' => 'bulk', 'created_at' => now()]);
        t7Chapter('t7-s-wait');

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $states = $this->downloads->statuses(['t7-s-none', 't7-s-cached', 't7-s-done', 't7-s-stop', 't7-s-run', 't7-s-feed', 't7-s-zero', 't7-s-wait']);

        expect($queries)->toBe(1)
            ->and(array_map(fn ($s) => $s->caption(), $states))->toBe([
                't7-s-none' => null,
                't7-s-cached' => null,
                't7-s-done' => 'Downloaded',
                't7-s-stop' => 'Download stopped · 33%',
                't7-s-run' => 'Downloading 25%',
                't7-s-feed' => 'Downloading 0%',
                't7-s-zero' => 'Downloading',
                't7-s-wait' => 'Queued',
            ]);
    });
});
