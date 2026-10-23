<?php

use App\Covers\CoverStore;
use App\Covers\FetchCovers;
use App\Jobs\DownloadCovers;
use App\MangaDex\RateLimit\Priority;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Native\Mobile\AsyncTask;
use Tests\Support\Covers;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 18:30:00', 'UTC'));
    $this->caches = Covers::useTempCaches();
    $this->store = app(CoverStore::class);
    $this->async = AsyncTask::fake();
    Queue::fake();
});

afterEach(fn () => AsyncTask::clearFake());

function fetchedPaths(): array
{
    return Http::recorded()->map(fn (array $pair) => parse_url($pair[0]->url(), PHP_URL_PATH))->all();
}

it('downloads the missing covers right away on the async lane, in screen order, without the queue', function () {
    Http::fake(['uploads.mangadex.org/*' => Http::response(Covers::jpeg())]);
    $stored = Covers::manga('m2');
    Covers::store($this->caches, $stored);
    $mangas = [Covers::manga('m3'), $stored, Covers::manga('m4', null), Covers::manga('m1')];

    $this->store->fetch($mangas);

    $this->async->assertDispatchedTimes(1);
    expect(fetchedPaths())->toBe(['/covers/m3/cover.jpg.256.jpg', '/covers/m1/cover.jpg.256.jpg'])
        ->and($this->store->path($mangas[3]))->not->toBeNull();
    Queue::assertNothingPushed();
});

it('starts no task when every cover is there', function () {
    $manga = Covers::manga('m1');
    Covers::store($this->caches, $manga);

    expect($this->store->fetch([$manga]))->toBeNull();

    $this->async->assertNotDispatched();
});

it('does not fetch the same cover again for ten minutes, even if a queued job already has it', function () {
    Http::fake(['uploads.mangadex.org/*' => Http::response('', 404)]);
    $manga = Covers::manga('m1');

    $this->store->queue([$manga], Priority::Prefetch);
    $this->store->fetch([$manga]);
    $this->travel(9)->minutes();
    $this->store->fetch([$manga]);

    $this->async->assertDispatchedTimes(1);

    $this->travel(2)->minutes();
    $this->store->fetch([$manga]);

    $this->async->assertDispatchedTimes(2);
});

it('hands the rest to the queue when MangaDex has no slot right now', function () {
    Http::fake(['uploads.mangadex.org/*' => Http::response(Covers::jpeg())]);
    $mangas = [Covers::manga('m1'), Covers::manga('m2')];
    DB::table('rate_buckets')->insert([
        'key' => 'family:mangadex.org', 'capacity' => 0, 'refill_per_sec' => 0, 'tokens' => 0,
        'updated_at' => now()->timestamp, 'blocked_until' => now()->timestamp + 300, 'strikes' => 0, 'probing' => false,
    ]);

    $this->store->fetch($mangas);

    Http::assertNothingSent();
    Queue::assertPushed(DownloadCovers::class, 1);
    Queue::assertPushed(DownloadCovers::class, fn (DownloadCovers $job) => $job->mangaIds === ['m1', 'm2']
        && $job->priority === Priority::Interactive);
});

it('hands the rest to the queue when the phone goes offline halfway', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/m2/')) {
            throw new ConnectionException('offline');
        }

        return Http::response(Covers::jpeg());
    });
    $mangas = [Covers::manga('m1'), Covers::manga('m2'), Covers::manga('m3')];

    $this->store->fetch($mangas);

    expect($this->store->path($mangas[0]))->not->toBeNull();
    Queue::assertPushed(DownloadCovers::class, fn (DownloadCovers $job) => $job->mangaIds === ['m2', 'm3']);
});

it('skips a broken cover and carries on', function () {
    Http::fake([
        'uploads.mangadex.org/covers/m1/*' => Http::response('', 404),
        'uploads.mangadex.org/*' => Http::response(Covers::jpeg()),
    ]);
    $mangas = [Covers::manga('m1'), Covers::manga('m2')];

    $this->store->fetch($mangas);

    expect($this->store->path($mangas[1]))->not->toBeNull();
    Queue::assertNothingPushed();
});

it('never fails the task: an unexpected error hands the rest to the queue', function () {
    Http::fake(fn () => throw new RuntimeException('boom'));
    Covers::manga('m1');

    $result = (new FetchCovers)->handle(['m1']);

    expect($result)->toBe(['fetched' => 0, 'queued' => ['m1']]);
    Queue::assertPushed(DownloadCovers::class, fn (DownloadCovers $job) => $job->mangaIds === ['m1']);
});
