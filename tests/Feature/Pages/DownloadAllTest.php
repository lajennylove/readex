<?php

use App\Downloads\Downloads;
use App\Downloads\DownloadState;
use App\Jobs\DownloadChapter;
use App\MangaDex\RateLimit\Priority;
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

    it('moves on when a chapter is not available', function () {
        Http::fake(['https://api.mangadex.org/at-home/server/*' => Http::response(['result' => 'ok', 'baseUrl' => 'https://a.mangadex.network', 'chapter' => ['hash' => 'h', 'data' => [], 'dataSaver' => []]])]);
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

    it('moves a chapter all in the cache on its turn without a job and goes on to the next', function () {
        Pages::fake(count: 4);
        t7aBatch(4);
        Stored::chapter('t7-a1', 2);
        Stored::chapter('t7-a3', 2);

        $this->downloads->downloadAll(T7_ALL, 'es-la');
        expect(t7aQueued())->toBe(['t7-a2 local bulk'])
            ->and(StoredChapter::query()->find('t7-a1')->tier)->toBe('local')
            ->and(StoredChapter::query()->find('t7-a3')->tier)->toBe('cache')
            ->and($this->downloads->running(T7_ALL))->toMatchArray(['done' => 1, 'total' => 4]);

        t7aRun('t7-a2');

        expect(t7aQueued())->toBe(['t7-a2 local bulk', 't7-a4 local bulk'])
            ->and(StoredChapter::query()->find('t7-a3')->tier)->toBe('local')
            ->and($this->downloads->running(T7_ALL))->toMatchArray(['done' => 3, 'total' => 4])
            ->and($this->downloads->status('t7-a3')->state)->toBe(DownloadState::Downloaded);
    });

    it('starts the next chapter once when two runtimes end the current one at the same time', function () {
        t7aBatch(3);
        $this->downloads->downloadAll(T7_ALL, 'es-la');
        $other = true;
        Download::retrieved(function (Download $entry) use (&$other) {
            if ($other && $entry->chapter_id === 't7-a1') {
                $other = false;
                app(Downloads::class)->ended('t7-a1');
            }
        });

        $this->downloads->ended('t7-a1');

        expect($other)->toBeFalse()
            ->and(t7aQueued())->toBe(['t7-a1 local bulk', 't7-a2 local bulk']);
    });

    it('counts a queued chapter the reader finished meanwhile without starting a second one', function () {
        Pages::fake(count: 4);
        t7aBatch(3);
        $this->downloads->downloadAll(T7_ALL, 'es-la');
        DownloadChapter::dispatch('t7-a3', 'cache', Priority::Interactive);

        t7aRun('t7-a3');
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
