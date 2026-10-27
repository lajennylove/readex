<?php

use App\Adapters\Http\RateLimit\Priority;
use App\Covers\CoverStore;
use App\Jobs\DownloadCovers;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Covers;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 18:30:00', 'UTC'));
    $this->caches = Covers::useTempCaches();
    $this->store = app(CoverStore::class);
});

const COVER_URL = 'https://uploads.source.test/covers/m1/cover.jpg.256.jpg';

describe('path', function () {
    it('is null until the thumbnail is on the phone, then the src through the cache link', function () {
        $manga = Covers::manga('m1');

        expect($this->store->path($manga))->toBeNull();

        Covers::store($this->caches, $manga);

        expect($this->store->path($manga))->toBe('storage/cache/covers/m1/cover.jpg.256.jpg');
    });

    it('is null for a series without a cover or with an unsafe file name', function (?string $file) {
        expect($this->store->path(Covers::manga('m1', $file)))->toBeNull();
    })->with([null, '', '../escape.jpg', 'a/b.jpg']);

    it('treats a purged file as not stored', function () {
        $manga = Covers::manga('m1');
        unlink(Covers::store($this->caches, $manga));

        expect($this->store->path($manga))->toBeNull();
    });
});

describe('download', function () {
    it('fetches the 256 px thumbnail through the covers bucket and stores it', function () {
        Http::fake(['uploads.source.test/*' => Http::response(Covers::jpeg(), 200, ['Content-Type' => 'image/jpeg'])]);
        $manga = Covers::manga('m1');

        expect($this->store->download($manga, Priority::Interactive))->toBeTrue()
            ->and($this->store->path($manga))->toBe('storage/cache/covers/m1/cover.jpg.256.jpg')
            ->and(file_get_contents("{$this->caches}/covers/m1/cover.jpg.256.jpg"))->toBe(Covers::jpeg())
            ->and(glob("{$this->caches}/covers/m1/*.part"))->toBe([])
            ->and(DB::table('api_usage')->where('bucket', 'covers')->sum('requests'))->toEqual(1);

        // uploads.source.test is the source's own host: nothing is reported to @Home.
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->url() === COVER_URL && $r->method() === 'GET');
    });

    it('skips a cover that is already stored', function () {
        Http::fake();
        $manga = Covers::manga('m1');
        Covers::store($this->caches, $manga);

        expect($this->store->download($manga, Priority::Interactive))->toBeTrue();

        Http::assertNothingSent();
    });

    it('stores nothing when the response is not an image', function () {
        Http::fake(['uploads.source.test/*' => Http::response('<html>captive portal</html>', 200)]);
        $manga = Covers::manga('m1');

        expect($this->store->download($manga, Priority::Interactive))->toBeFalse()
            ->and($this->store->path($manga))->toBeNull()
            ->and(glob("{$this->caches}/covers/m1/*"))->toBe([]);
    });

    it('sends nothing for a series without a cover or with an unsafe file name', function (?string $file) {
        Http::fake(['uploads.source.test/*' => Http::response(Covers::jpeg())]);

        expect($this->store->download(Covers::manga('m1', $file), Priority::Interactive))->toBeFalse();

        Http::assertNothingSent();
    })->with([null, '../escape.jpg']);

    it('replaces the old cover when the series gets a new one', function () {
        Http::fake(['uploads.source.test/*' => Http::response(Covers::jpeg())]);
        $manga = Covers::manga('m1', 'old.jpg');
        $old = Covers::store($this->caches, $manga);
        $manga->update(['cover_file' => 'new.png']);

        $this->store->download($manga, Priority::Interactive);

        expect(file_exists($old))->toBeFalse()
            ->and($this->store->path($manga))->toBe('storage/cache/covers/m1/new.png.256.jpg');
    });
});

describe('queue', function () {
    beforeEach(fn () => Queue::fake());

    it('queues one job with the missing covers in screen order', function () {
        $stored = Covers::manga('m2');
        Covers::store($this->caches, $stored);
        $mangas = [Covers::manga('m3'), $stored, Covers::manga('m4', null), Covers::manga('m1')];

        $this->store->queue($mangas, Priority::Interactive);

        Queue::assertPushed(DownloadCovers::class, 1);
        Queue::assertPushed(DownloadCovers::class, fn (DownloadCovers $job) => $job->mangaIds === ['m3', 'm1']
            && $job->priority === Priority::Interactive);
    });

    it('queues nothing when every cover is there', function () {
        $manga = Covers::manga('m1');
        Covers::store($this->caches, $manga);

        $this->store->queue([$manga], Priority::Prefetch);

        Queue::assertNothingPushed();
    });

    it('does not queue the same cover again for ten minutes', function () {
        $manga = Covers::manga('m1');

        $this->store->queue([$manga], Priority::Interactive);
        $this->travel(9)->minutes();
        $this->store->queue([$manga], Priority::Interactive);

        Queue::assertPushed(DownloadCovers::class, 1);

        $this->travel(2)->minutes();
        $this->store->queue([$manga], Priority::Interactive);

        Queue::assertPushed(DownloadCovers::class, 2);
    });

    it('queues a new cover file at once', function () {
        $manga = Covers::manga('m1', 'old.jpg');
        $this->store->queue([$manga], Priority::Interactive);
        $manga->update(['cover_file' => 'new.jpg']);

        $this->store->queue([$manga], Priority::Interactive);

        Queue::assertPushed(DownloadCovers::class, 2);
    });
});
