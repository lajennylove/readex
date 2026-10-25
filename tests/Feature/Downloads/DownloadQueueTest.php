<?php

declare(strict_types=1);

use App\Adapters\Http\RateLimit\Priority;
use App\Downloads\DownloadQueue;
use App\Downloads\Downloads;
use App\Downloads\QueueState;
use App\Jobs\DownloadChapter;
use App\Models\Chapter;
use App\Models\Download;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Native\Mobile\AsyncTask;
use Tests\Support\Covers;
use Tests\Support\Pages;
use Tests\Support\Stored;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 05:00:00', 'UTC'));
    Sleep::fake(syncWithCarbon: true);
    Covers::useTempCaches();
    Stored::useTempStorage();
    Queue::fake();
    $this->async = AsyncTask::fake();
    $this->queue = app(DownloadQueue::class);
    $this->downloads = app(Downloads::class);
});

function q21Chapter(string $id, string $manga = 'q21-m', int $pages = 4, array $extra = []): Chapter
{
    return Chapter::query()->create([
        'id' => $id, 'manga_id' => $manga, 'volume' => null, 'number' => $id, 'title' => null,
        'language' => 'en', 'group_id' => 'g', 'group_name' => 'G', 'pages' => $pages,
        'external_url' => null, ...$extra,
    ]);
}

/** @return list<string> chapter_ids in position order from the downloads table */
function q21Positions(): array
{
    return Download::query()->orderBy('position')->pluck('chapter_id')->all();
}

describe('enqueueBatch', function () {
    it('appends every chapter to the end of the queue in arrival order', function () {
        $batchA = [q21Chapter('a1'), q21Chapter('a2'), q21Chapter('a3')];

        $n = $this->queue->enqueueBatch('q21-m', 'en', $batchA);

        expect($n)->toBe(3)
            ->and(q21Positions())->toBe(['a1', 'a2', 'a3'])
            ->and(Download::query()->pluck('state', 'chapter_id')->map(fn ($s) => $s->value)->all())
            ->toBe(['a1' => 'queued', 'a2' => 'queued', 'a3' => 'queued']);
    });

    it('appends a second batch after the first, preserving arrival order across batches', function () {
        $this->queue->enqueueBatch('m1', 'en', [q21Chapter('a1', 'm1'), q21Chapter('a2', 'm1')]);
        $this->queue->enqueueBatch('m2', 'en', [q21Chapter('b1', 'm2'), q21Chapter('b2', 'm2')]);

        expect(q21Positions())->toBe(['a1', 'a2', 'b1', 'b2']);
    });

    it('kicks a DownloadChapter at Bulk priority so the queue starts draining', function () {
        $this->queue->enqueueBatch('m1', 'en', [q21Chapter('a1')]);

        expect(Queue::pushed(DownloadChapter::class))->toHaveCount(1)
            ->and(Queue::pushed(DownloadChapter::class)->first()->priority)->toBe(Priority::Bulk);
    });
});

describe('enqueueOne', function () {
    it('appends and dedupes if already present', function () {
        q21Chapter('a1');

        $this->queue->enqueueOne('a1');
        $this->queue->enqueueOne('a1');

        expect(Download::query()->count())->toBe(1);
    });

    it('is a no-op for an unknown chapter id', function () {
        $this->queue->enqueueOne('ghost');

        expect(Download::query()->count())->toBe(0);
    });
});

describe('readNow', function () {
    it('on an unqueued chapter: fills the comodin lane, dispatches FetchPages at Interactive, no queue rows touched', function () {
        q21Chapter('read-1');
        q21Chapter('bg-1');
        $this->queue->enqueueBatch('q21-m', 'en', [Chapter::find('bg-1')]);

        $returned = $this->queue->readNow('read-1');

        expect($returned?->id)->toBe('read-1')
            ->and($this->queue->readNowChapterId())->toBe('read-1')
            ->and(Download::query()->where('chapter_id', 'bg-1')->exists())->toBeTrue()
            ->and(Download::query()->where('chapter_id', 'read-1')->exists())->toBeFalse();

        $this->async->assertDispatchedTimes(1);
    });

    it('on a queued chapter: removes the row so we do not double-download, fills comodin, dispatches Interactive FetchPages', function () {
        q21Chapter('x');
        $this->queue->enqueueOne('x');
        expect(Download::query()->where('chapter_id', 'x')->exists())->toBeTrue();

        $this->queue->readNow('x');

        expect(Download::query()->where('chapter_id', 'x')->exists())->toBeFalse()
            ->and($this->queue->readNowChapterId())->toBe('x');
        $this->async->assertDispatchedTimes(1);
    });

    it('fires while the background lane is actively downloading another chapter — background chapter stays Downloading, both run in parallel', function () {
        q21Chapter('bg');
        q21Chapter('tap');
        // Simulate the background lane in flight by directly flipping bg's state.
        Download::query()->insert([
            'chapter_id' => 'bg', 'manga_id' => 'q21-m', 'batch' => null, 'position' => 1,
            'priority' => Priority::Bulk->value, 'state' => QueueState::Downloading->value,
            'started_at' => now(), 'finished_at' => null, 'created_at' => now(),
        ]);

        $this->queue->readNow('tap');

        // Background chapter did NOT pause.
        expect(Download::query()->whereKey('bg')->first()->state)->toBe(QueueState::Downloading)
            ->and($this->queue->readNowChapterId())->toBe('tap');
        $this->async->assertDispatchedTimes(1);
    });

    it('returns null for an unknown chapter id', function () {
        expect($this->queue->readNow('ghost'))->toBeNull()
            ->and($this->queue->readNowChapterId())->toBeNull();
    });
});

describe('nextRunnable', function () {
    it('returns the oldest queued row and never a paused row', function () {
        q21Chapter('a1');
        q21Chapter('a2');
        q21Chapter('a3');
        $this->queue->enqueueBatch('q21-m', 'en', [Chapter::find('a1'), Chapter::find('a2'), Chapter::find('a3')]);
        $this->queue->pause('a1');

        expect($this->queue->nextRunnable()->chapter_id)->toBe('a2');
    });

    it('never returns the comodin chapter', function () {
        q21Chapter('a1');
        q21Chapter('tap');
        $this->queue->enqueueBatch('q21-m', 'en', [Chapter::find('a1')]);
        $this->queue->readNow('tap');

        expect($this->queue->nextRunnable()->chapter_id)->toBe('a1');
    });
});

describe('completion chain', function () {
    it('a background chapter finishing triggers nextRunnable → next DownloadChapter dispatched automatically', function () {
        Pages::fake(count: 2);
        q21Chapter('a1', pages: 2);
        q21Chapter('a2', pages: 2);
        $this->queue->enqueueBatch('q21-m', 'en', [Chapter::find('a1'), Chapter::find('a2')]);
        Queue::fake(); // reset so we only see the chain dispatch

        // Simulate a1 finishing by calling Downloads::finished (which flows into ended()->kick()).
        app(Downloads::class)->finished('a1');

        expect(Queue::pushed(DownloadChapter::class)->pluck('chapterId')->all())->toContain('a2');
    });
});

describe('DiskSpaceLow', function () {
    it('returns the Downloading row to queued, not stuck', function () {
        q21Chapter('a1');
        Download::query()->insert([
            'chapter_id' => 'a1', 'manga_id' => 'q21-m', 'batch' => null, 'position' => 1,
            'priority' => Priority::Bulk->value, 'state' => QueueState::Downloading->value,
            'started_at' => now(), 'finished_at' => null, 'created_at' => now(),
        ]);

        $this->queue->release('a1');

        $row = Download::query()->whereKey('a1')->first();
        expect($row->state)->toBe(QueueState::Queued)
            ->and($row->started_at)->toBeNull();
    });
});

describe('pause / resume / cancel', function () {
    it('pause flips queued → paused and resume flips it back', function () {
        q21Chapter('a1');
        $this->queue->enqueueOne('a1');

        $this->queue->pause('a1');
        expect(Download::query()->whereKey('a1')->first()->state)->toBe(QueueState::Paused);

        $this->queue->resume('a1');
        expect(Download::query()->whereKey('a1')->first()->state)->toBe(QueueState::Queued);
    });

    it('cancel on a downloading row transitions to canceling (worker observes mid-slice)', function () {
        q21Chapter('a1');
        Download::query()->insert([
            'chapter_id' => 'a1', 'manga_id' => 'q21-m', 'batch' => null, 'position' => 1,
            'priority' => Priority::Bulk->value, 'state' => QueueState::Downloading->value,
            'started_at' => now(), 'finished_at' => null, 'created_at' => now(),
        ]);

        $this->queue->cancel('a1');

        expect(Download::query()->whereKey('a1')->first()->state)->toBe(QueueState::Canceling)
            ->and($this->queue->isCanceling('a1'))->toBeTrue();
    });

    it('cancel on a queued or paused row deletes the row outright (Q2: a cancelled chapter is not "done", it is not going to happen)', function () {
        q21Chapter('a1');
        q21Chapter('a2');
        $this->queue->enqueueOne('a1');
        $this->queue->enqueueOne('a2');
        $this->queue->pause('a2');

        $this->queue->cancel('a1');
        $this->queue->cancel('a2');

        expect(Download::query()->whereKey('a1')->exists())->toBeFalse()
            ->and(Download::query()->whereKey('a2')->exists())->toBeFalse();
    });
});

describe('cancelAllForManga', function () {
    it('deletes queued/paused rows, flips downloading rows to canceling, leaves done + other mangas untouched, and returns the count', function () {
        q21Chapter('a1', 'm-target');
        q21Chapter('a2', 'm-target');
        q21Chapter('a3', 'm-target');
        q21Chapter('a4', 'm-target');
        q21Chapter('b1', 'm-other');

        // m-target: a1 queued, a2 paused, a3 downloading, a4 done
        $this->queue->enqueueOne('a1');
        $this->queue->enqueueOne('a2');
        $this->queue->pause('a2');
        Download::query()->insert([
            'chapter_id' => 'a3', 'manga_id' => 'm-target', 'batch' => null, 'position' => 50,
            'priority' => Priority::Bulk->value, 'state' => QueueState::Downloading->value,
            'started_at' => now(), 'finished_at' => null, 'created_at' => now(),
        ]);
        Download::query()->insert([
            'chapter_id' => 'a4', 'manga_id' => 'm-target', 'batch' => null, 'position' => 60,
            'priority' => Priority::Bulk->value, 'state' => QueueState::Done->value,
            'started_at' => now(), 'finished_at' => now(), 'created_at' => now(),
        ]);
        // m-other: untouched baseline
        $this->queue->enqueueOne('b1');

        $affected = $this->queue->cancelAllForManga('m-target');

        expect($affected)->toBe(3)
            ->and(Download::query()->whereKey('a1')->exists())->toBeFalse()
            ->and(Download::query()->whereKey('a2')->exists())->toBeFalse()
            ->and(Download::query()->whereKey('a3')->first()->state)->toBe(QueueState::Canceling)
            ->and(Download::query()->whereKey('a4')->first()->state)->toBe(QueueState::Done)
            ->and(Download::query()->whereKey('b1')->first()->state)->toBe(QueueState::Queued);
    });

    it('returns 0 when the manga has no non-done rows', function () {
        q21Chapter('a1', 'm-target');
        Download::query()->insert([
            'chapter_id' => 'a1', 'manga_id' => 'm-target', 'batch' => null, 'position' => 1,
            'priority' => Priority::Bulk->value, 'state' => QueueState::Done->value,
            'started_at' => now(), 'finished_at' => now(), 'created_at' => now(),
        ]);

        expect($this->queue->cancelAllForManga('m-target'))->toBe(0)
            ->and(Download::query()->whereKey('a1')->first()->state)->toBe(QueueState::Done);
    });
});

describe('pending', function () {
    it('excludes done rows and the comodin chapter', function () {
        q21Chapter('a1');
        q21Chapter('a2');
        q21Chapter('a3');
        q21Chapter('tap');
        $this->queue->enqueueBatch('q21-m', 'en', [
            Chapter::find('a1'), Chapter::find('a2'), Chapter::find('a3'),
        ]);
        // Mark a1 done manually.
        Download::query()->whereKey('a1')->update([
            'state' => QueueState::Done->value, 'finished_at' => now(),
        ]);
        $this->queue->readNow('tap'); // tap is NOT in queue, lane only

        $pending = $this->queue->pending()->pluck('chapter_id')->all();

        expect($pending)->toBe(['a2', 'a3']);
    });
});
