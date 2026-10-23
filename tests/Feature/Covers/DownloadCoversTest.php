<?php

use App\Covers\CoverStore;
use App\Jobs\DownloadCovers;
use App\MangaDex\RateLimit\Priority;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Covers;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 18:30:00', 'UTC'));
    $this->caches = Covers::useTempCaches();
    $this->covers = app(CoverStore::class);
});

function runCovers(array $ids, Priority $priority = Priority::Interactive): DownloadCovers
{
    $job = (new DownloadCovers($ids, $priority))->withFakeQueueInteractions();
    $job->handle(app(CoverStore::class));

    return $job;
}

function coverPaths(): array
{
    return Http::recorded()->map(fn (array $pair) => parse_url($pair[0]->url(), PHP_URL_PATH))->all();
}

it('downloads the covers in the order given and skips stored or unknown ones', function () {
    Http::fake(['uploads.mangadex.org/*' => Http::response(Covers::jpeg())]);
    Covers::manga('m1');
    Covers::store($this->caches, Covers::manga('m2'));
    Covers::manga('m3');

    runCovers(['m3', 'gone', 'm2', 'm1'])->assertNotReleased();

    expect(coverPaths())->toBe(['/covers/m3/cover.jpg.256.jpg', '/covers/m1/cover.jpg.256.jpg'])
        ->and(glob("{$this->caches}/covers/*/*.256.jpg"))->toHaveCount(3);
});

it('waits for the controller when MangaDex is limiting the phone', function () {
    Http::fake(['uploads.mangadex.org/*' => Http::response(Covers::jpeg())]);
    Covers::manga('m1');
    DB::table('rate_buckets')->insert([
        'key' => 'family:mangadex.org', 'capacity' => 0, 'refill_per_sec' => 0, 'tokens' => 0,
        'updated_at' => now()->timestamp, 'blocked_until' => now()->timestamp + 300, 'strikes' => 0, 'probing' => false,
    ]);

    runCovers(['m1'])->assertReleased(300);

    Http::assertNothingSent();
});

it('tries again in a minute when the phone is offline', function () {
    Http::fake(fn () => throw new ConnectionException('offline'));
    Covers::manga('m1');

    runCovers(['m1'])->assertReleased(DownloadCovers::OFFLINE_RETRY_SECONDS);
});

it('skips a broken cover and carries on', function () {
    Http::fake([
        'uploads.mangadex.org/covers/m1/*' => Http::response('', 404),
        'uploads.mangadex.org/*' => Http::response(Covers::jpeg()),
    ]);
    Covers::manga('m1');
    $m2 = Covers::manga('m2');

    runCovers(['m1', 'm2'])->assertNotReleased();

    expect($this->covers->path($m2))->not->toBeNull();
});

it('gives up after an hour', function () {
    expect((new DownloadCovers(['m1'], Priority::Prefetch))->retryUntil()->toDateTimeString())
        ->toBe('2026-10-23 19:30:00');
});
