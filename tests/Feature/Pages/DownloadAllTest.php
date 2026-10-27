<?php

use App\Adapters\Http\RateLimit\Priority;
use App\Downloads\Downloads;
use App\Downloads\DownloadState;
use App\Jobs\DownloadChapter;
use App\Models\Chapter;
use App\Models\Collection as LibraryEntry;
use App\Models\Download;
use App\Models\StoredChapter;
use App\Pages\PageFetcher;
use App\Repositories\LibraryRepository;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\Covers;
use Tests\Support\Pages;
use Tests\Support\Stored;

const T7_ALL = 't7-all';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 04:00:00', 'UTC'));
    Sleep::fake(syncWithCarbon: true);
    $this->caches = Covers::useTempCaches();
    Stored::useTempStorage();
    Queue::fake();
    $this->downloads = app(Downloads::class);
});

function t7aChapter(string $id, ?string $number, array $extra = []): Chapter
{
    return Chapter::query()->create([
        'id' => $id, 'manga_id' => T7_ALL, 'volume' => null, 'number' => $number, 'title' => null,
        'language' => 'es-la', 'group_id' => 't7-ga', 'group_name' => 'Group A', 'pages' => 4,
        'external_url' => null, ...$extra,
    ]);
}

/** @return list<string> "id tier priority" of every DownloadChapter queued, in order */
function t7aQueued(): array
{
    return Queue::pushed(DownloadChapter::class)->map(fn (DownloadChapter $j) => "{$j->chapterId} {$j->tier} {$j->priority->value}")->values()->all();
}

/** Run the last DownloadChapter queued for this chapter once. */
function t7aRun(string $chapterId): DownloadChapter
{
    $job = Queue::pushed(DownloadChapter::class)->filter(fn (DownloadChapter $j) => $j->chapterId === $chapterId)->last()->withFakeQueueInteractions();
    $job->handle(app(PageFetcher::class));

    return $job;
}

function t7aBatch(int $count): void
{
    foreach (range(1, $count) as $n) {
        t7aChapter("t7-a{$n}", (string) $n);
    }
}

describe('scope', function () {
    it('takes the readable chapters of the language, one upload per number as the Library picks it, minus downloaded ones, in reading order', function () {
        t7aChapter('t7-a3', '3');
        t7aChapter('t7-a1', '1');
        t7aChapter('t7-a2', '2');
        t7aChapter('t7-b2', '2', ['group_id' => 't7-gb', 'group_name' => 'Group B']);
        t7aChapter('t7-ext4', '4', ['external_url' => 'https://mangaplus.shueisha.co.jp/viewer/4']);
        t7aChapter('t7-en5', '5', ['language' => 'en']);
        t7aChapter('t7-done6', '6');
        Stored::chapter('t7-done6', 2, tier: 'local');
        t7aChapter('t7-cached7', '7');
        Stored::chapter('t7-cached7', 2);
        t7aChapter('t7-stopped8', '8');
        Stored::partial('t7-stopped8', 1, 4, tier: 'local');
        app(LibraryRepository::class)->setPreferredGroup(T7_ALL, 't7-gb');

        $plan = $this->downloads->plan(T7_ALL, 'es-la');

        expect($plan['readable'])->toBe(6)
            ->and($plan['downloaded'])->toBe(1)
            ->and($plan['chapters']->pluck('id')->all())->toBe(['t7-a1', 't7-b2', 't7-a3', 't7-cached7', 't7-stopped8'])
            ->and($this->downloads->plan(T7_ALL, 'en')['chapters']->pluck('id')->all())->toBe(['t7-en5'])
            ->and($this->downloads->plan(T7_ALL, 'fr'))->toMatchArray(['readable' => 0])
            ->and($this->downloads->plan(T7_ALL, 'fr')['chapters']->all())->toBe([]);
    });

    it('leaves out a chapter already queued on its own', function () {
        t7aBatch(2);
        $this->downloads->download('t7-a1');

        expect($this->downloads->plan(T7_ALL, 'es-la')['chapters']->pluck('id')->all())->toBe(['t7-a2']);
    });
});

describe('Download all', function () {
    it('queues the chapters as one batch and dispatches only the first, at bulk priority, adding the series to the Library', function () {
        t7aBatch(3);
        t7aChapter('t7-b0', '0', ['group_id' => 't7-gb']);

        expect($this->downloads->downloadAll(T7_ALL, 'es-la'))->toBe(4);

        expect(t7aQueued())->toBe(['t7-b0 local bulk'])
            ->and(Download::query()->orderBy('position')->pluck('chapter_id')->all())->toBe(['t7-b0', 't7-a1', 't7-a2', 't7-a3'])
            ->and(Download::query()->distinct()->count('batch'))->toBe(1)
            ->and(LibraryEntry::query()->find(T7_ALL)?->preferred_group_id)->toBe('t7-gb')
            ->and(array_map(fn ($s) => $s->caption(), $this->downloads->statuses(['t7-b0', 't7-a1'])))->toBe(['t7-b0' => 'Queued', 't7-a1' => 'Queued'])
            ->and($this->downloads->running(T7_ALL))->toBe(['done' => 0, 'total' => 4, 'percent' => 0]);
    });

    it('starts the next chapter when one is done, one at a time', function () {
        Pages::fake(count: 4);
        t7aBatch(3);
        $this->downloads->downloadAll(T7_ALL, 'es-la');

        t7aRun('t7-a1');
        expect(t7aQueued())->toBe(['t7-a1 local bulk', 't7-a2 local bulk']);

        t7aRun('t7-a2');
        t7aRun('t7-a3');

        expect(t7aQueued())->toBe(['t7-a1 local bulk', 't7-a2 local bulk', 't7-a3 local bulk'])
            ->and(array_map(fn ($s) => $s->state, $this->downloads->statuses(['t7-a1', 't7-a2', 't7-a3'])))->each->toBe(DownloadState::Downloaded)
            ->and($this->downloads->running(T7_ALL))->toBeNull();
    });

    it('asks again for the pages that failed in each chapter of the batch', function () {
        // Plan 25: downloadAll() routes through DownloadQueue::enqueueBatch, so
        // the "Download is a retry" clear-failed happens up front for every
        // chapter in the batch rather than one at a time at each chapter's
        // turn. End result is the same — a retry asks for the failed pages
        // again — and chapters that never make it to the fetch step have
        // nothing to retry anyway.
        Pages::fake(count: 4);
        t7aBatch(2);
        Stored::partial('t7-a1', 2, 4);
        Stored::partial('t7-a2', 3, 4);
        cache()->put('readex.pages.failed.t7-a1', [2, 3], 86400);
        cache()->put('readex.pages.failed.t7-a2', [3], 86400);
        $fetcher = app(PageFetcher::class);

        $this->downloads->downloadAll(T7_ALL, 'es-la');

        expect($fetcher->failed('t7-a1'))->toBe([])
            ->and($fetcher->failed('t7-a2'))->toBe([]);

        t7aRun('t7-a1');
        t7aRun('t7-a2');

        expect(array_map(fn ($s) => $s->state, $this->downloads->statuses(['t7-a1', 't7-a2'])))->each->toBe(DownloadState::Downloaded)
            ->and($this->downloads->running(T7_ALL))->toBeNull();
    });

    it('moves on when a chapter is not available', function () {
        Http::fake(['https://api.source.test/at-home/server/*' => Http::response(['result' => 'ok', 'baseUrl' => 'https://a.source.network.test', 'chapter' => ['hash' => 'h', 'data' => [], 'dataSaver' => []]])]);
        t7aBatch(2);
        $this->downloads->downloadAll(T7_ALL, 'es-la');

        t7aRun('t7-a1');

        expect(t7aQueued())->toBe(['t7-a1 local bulk', 't7-a2 local bulk']);
    });

    it('moves on when a chapter that is gone or licensed comes up', function () {
        t7aBatch(3);
        $this->downloads->downloadAll(T7_ALL, 'es-la');
        Chapter::query()->whereKey('t7-a1')->delete();

        t7aRun('t7-a1');
        expect(t7aQueued())->toBe(['t7-a1 local bulk', 't7-a2 local bulk']);

        Chapter::query()->whereKey('t7-a2')->update(['external_url' => 'https://mangaplus.shueisha.co.jp/viewer/2']);
        t7aRun('t7-a2');
        expect(t7aQueued())->toBe(['t7-a1 local bulk', 't7-a2 local bulk', 't7-a3 local bulk']);
    });

    it('moves on when a job fails for good', function () {
        t7aBatch(2);
        $this->downloads->downloadAll(T7_ALL, 'es-la');

        Queue::pushed(DownloadChapter::class)->first()->failed(new RuntimeException('gave up'));

        expect(t7aQueued())->toBe(['t7-a1 local bulk', 't7-a2 local bulk']);
    });

    it('waits while offline: the job is released and nothing else starts', function () {
        Http::fake(fn () => throw new ConnectionException('offline'));
        t7aBatch(2);
        $this->downloads->downloadAll(T7_ALL, 'es-la');

        t7aRun('t7-a1')->assertReleased(DownloadChapter::OFFLINE_RETRY_SECONDS);

        expect(t7aQueued())->toBe(['t7-a1 local bulk'])
            ->and($this->downloads->isQueued('t7-a1'))->toBeTrue();
    });

    it('waits while the source is having trouble: the job is released and nothing else starts', function () {
        Http::fake(['https://api.source.test/at-home/server/*' => Http::response(['result' => 'error', 'errors' => [['status' => 503]]], 503)]);
        t7aBatch(3);
        $this->downloads->downloadAll(T7_ALL, 'es-la');

        t7aRun('t7-a1')->assertReleased(1);
        t7aRun('t7-a1')->assertReleased(4);

        expect(t7aQueued())->toBe(['t7-a1 local bulk'])
            ->and($this->downloads->isQueued('t7-a1'))->toBeTrue()
            ->and($this->downloads->running(T7_ALL))->toMatchArray(['done' => 0, 'total' => 3]);
    });

    it('moves a chapter all in the cache on its turn without asking for a page and goes on to the next', function () {
        // Plan 25: a chapter already fully in the cache is still "moved without
        // asking for a page", but the move happens inside the DownloadChapter
        // job at its turn (via PageStore::promote) rather than synchronously
        // inside downloadAll(). Running each queued job in turn exercises both
        // the cached-moved-no-fetch path (a1, a3) and the fetch path (a2, a4).
        Pages::fake(count: 4);
        t7aBatch(4);
        Stored::chapter('t7-a1', 2);
        Stored::chapter('t7-a3', 2);

        $this->downloads->downloadAll(T7_ALL, 'es-la');
        expect(t7aQueued())->toBe(['t7-a1 local bulk'])
            ->and($this->downloads->running(T7_ALL))->toMatchArray(['done' => 0, 'total' => 4]);

        t7aRun('t7-a1');
        expect(StoredChapter::query()->find('t7-a1')->tier)->toBe('local')
            ->and($this->downloads->running(T7_ALL))->toMatchArray(['done' => 1, 'total' => 4])
            ->and(t7aQueued())->toBe(['t7-a1 local bulk', 't7-a2 local bulk']);

        t7aRun('t7-a2');
        t7aRun('t7-a3');

        expect(StoredChapter::query()->find('t7-a3')->tier)->toBe('local')
            ->and($this->downloads->running(T7_ALL))->toMatchArray(['done' => 3, 'total' => 4])
            ->and($this->downloads->status('t7-a3')->state)->toBe(DownloadState::Downloaded)
            ->and(t7aQueued())->toBe(['t7-a1 local bulk', 't7-a2 local bulk', 't7-a3 local bulk', 't7-a4 local bulk']);
    });

    it('starts the next chapter once when two runtimes end the current one at the same time', function () {
        t7aBatch(3);
        $this->downloads->downloadAll(T7_ALL, 'es-la');

        // chore/0025: ended() now routes through DownloadQueue::markDone which does a
        // single atomic UPDATE with a state WHERE-clause. The old race hook was
        // Download::retrieved, which only fired on model first() — markDone writes
        // without reading, so we simulate the race directly: two ended() calls in a
        // row. The second one must be a no-op (isQueued=false after the first succeeds)
        // and only one chain-kick for a2 happens.
        $this->downloads->ended('t7-a1');
        $this->downloads->ended('t7-a1');

        expect(t7aQueued())->toBe(['t7-a1 local bulk', 't7-a2 local bulk']);
    });

    it('counts a queued chapter the reader finished meanwhile without starting a second one', function () {
        Pages::fake(count: 4);
        t7aBatch(3);
        $this->downloads->downloadAll(T7_ALL, 'es-la');
        DownloadChapter::dispatch('t7-a3', 'cache', Priority::Interactive);

        t7aRun('t7-a3');
        // a3's chain-kick re-targets a1 (position 1, still queued) but DownloadChapter
        // is ShouldBeUnique so the duplicate dispatch is dropped — Queue::pushed stays
        // at [a1, a3]. The batch counter correctly shows 1 done / 3 total.
        expect(t7aQueued())->toBe(['t7-a1 local bulk', 't7-a3 cache interactive'])
            ->and($this->downloads->running(T7_ALL))->toMatchArray(['done' => 1, 'total' => 3]);

        t7aRun('t7-a1');
        t7aRun('t7-a2');

        expect(t7aQueued())->toBe(['t7-a1 local bulk', 't7-a3 cache interactive', 't7-a2 local bulk'])
            ->and($this->downloads->running(T7_ALL))->toBeNull();
    });

    it('queues nothing while a batch of the series runs', function () {
        t7aBatch(2);
        $this->downloads->downloadAll(T7_ALL, 'es-la');
        t7aChapter('t7-en1', '1', ['language' => 'en']);

        expect($this->downloads->downloadAll(T7_ALL, 'en'))->toBe(0)
            ->and(t7aQueued())->toBe(['t7-a1 local bulk']);
    });

    it('can run again after a batch: chapters already downloaded are skipped', function () {
        Pages::fake(count: 4);
        t7aBatch(2);
        $this->downloads->downloadAll(T7_ALL, 'es-la');
        t7aRun('t7-a1');
        $this->downloads->stopAll(T7_ALL);

        expect($this->downloads->downloadAll(T7_ALL, 'es-la'))->toBe(1)
            ->and(Download::query()->whereNull('finished_at')->pluck('chapter_id')->all())->toBe(['t7-a2'])
            ->and($this->downloads->running(T7_ALL))->toMatchArray(['done' => 0, 'total' => 1]);
    });
});

describe('progress and Stop', function () {
    it('counts finished chapters and stored pages over the batch, page counts from the stored chapter else the feed', function () {
        Pages::fake(count: 4);
        t7aBatch(2);
        t7aChapter('t7-a3', '3', ['pages' => 5]);
        $this->downloads->downloadAll(T7_ALL, 'es-la');
        t7aRun('t7-a1');
        Stored::partial('t7-a2', 1, 3, tier: 'local');

        expect($this->downloads->running(T7_ALL))->toBe(['done' => 1, 'total' => 3, 'percent' => 41]);
    });

    it('drops the chapters not started, keeps the finished ones, leaves the current one partial and stops its job', function () {
        Pages::fake(count: 4);
        t7aBatch(3);
        $this->downloads->downloadAll(T7_ALL, 'es-la');
        t7aRun('t7-a1');
        Stored::partial('t7-a2', 1, 4, tier: 'local');

        $this->downloads->stopAll(T7_ALL);

        expect($this->downloads->running(T7_ALL))->toBeNull()
            ->and(Download::query()->pluck('chapter_id')->all())->toBe(['t7-a1']);

        Http::fake(fn () => throw new RuntimeException('no network'));
        t7aRun('t7-a2')->assertNotReleased();

        expect(t7aQueued())->toBe(['t7-a1 local bulk', 't7-a2 local bulk'])
            ->and(array_map(fn ($s) => $s->caption(), $this->downloads->statuses(['t7-a1', 't7-a2', 't7-a3'])))
            ->toBe(['t7-a1' => 'Downloaded', 't7-a2' => 'Download stopped · 25%', 't7-a3' => null]);
    });

    it('starts the next chapter when the current one is stopped on its own; a later one stopped is just skipped', function () {
        t7aBatch(4);
        $this->downloads->downloadAll(T7_ALL, 'es-la');

        $this->downloads->stop('t7-a3');
        expect(t7aQueued())->toBe(['t7-a1 local bulk']);

        $this->downloads->stop('t7-a1');
        expect(t7aQueued())->toBe(['t7-a1 local bulk', 't7-a2 local bulk']);

        Queue::pushed(DownloadChapter::class)->last()->failed(new RuntimeException('gave up'));
        expect(t7aQueued())->toBe(['t7-a1 local bulk', 't7-a2 local bulk', 't7-a4 local bulk']);
    });
});
