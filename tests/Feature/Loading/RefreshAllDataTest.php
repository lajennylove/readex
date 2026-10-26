<?php

use App\Jobs\RefreshAllData;
use App\Loading\LoadProgress;
use App\Repositories\HomeRepository;
use App\Repositories\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\Covers;
use Tests\Support\Loading;

beforeEach(function () {
    Carbon::setTestNow('2026-10-24 12:00:00');
    Sleep::fake(syncWithCarbon: true);
    Covers::useTempCaches();
    Queue::fake();
    app(SettingsRepository::class)->setLanguage('es-la');
});

it('marks itself running and queues the refill', function () {
    RefreshAllData::start('r1');

    expect(RefreshAllData::running())->toBe('r1')
        ->and(LoadProgress::read('r1'))->toBe([]);
    Queue::assertPushed(RefreshAllData::class, fn (RefreshAllData $job) => $job->run === 'r1');
});

it('runs the loading steps, records the refresh time and stops running', function () {
    Loading::fake();
    Cache::put(RefreshAllData::RUNNING_KEY, 'r1');

    app()->call([new RefreshAllData('r1'), 'handle']);

    expect(LoadProgress::read('r1'))->toBe(['tags' => 'ok', 'popular' => 'ok', 'latest' => 'ok', 'covers' => 'ok'])
        ->and(app(HomeRepository::class)->latest()->isEmpty())->toBeFalse()
        ->and(app(SettingsRepository::class)->lastDataRefreshAt()?->gte('2026-10-24 12:00:00'))->toBeTrue()
        ->and(RefreshAllData::running())->toBeNull();
});

it('sends what comes after the Home rows as bulk (spec §7.1)', function () {
    Loading::fake();

    app()->call([new RefreshAllData('r1'), 'handle']);

    // Only prefetch/bulk calls take the background share of the global budget; tags are the one global call after the rows.
    expect(DB::table('rate_buckets')->where('key', 'global:background')->exists())->toBeTrue();
});

it('keeps the old refresh time when no Home row loaded', function () {
    // Fail everything except the Setup catalog health check (which Loading::fake
    // itself answers before the override runs). Covers under a Home row succeed on
    // 503 of their source feed, so leaving them available is still fine for the
    // "no row loaded" assertion.
    Loading::fake(fn () => Http::response('down', 503));
    Cache::put(RefreshAllData::RUNNING_KEY, 'r1');

    app()->call([new RefreshAllData('r1'), 'handle']);

    expect(app(SettingsRepository::class)->lastDataRefreshAt())->toBeNull()
        ->and(LoadProgress::read('r1')['popular'])->toBe('unavailable')
        ->and(RefreshAllData::running())->toBeNull();
});

it('stops running when the job fails, but leaves a newer refill alone', function () {
    Cache::put(RefreshAllData::RUNNING_KEY, 'r1');
    (new RefreshAllData('r0'))->failed(new RuntimeException('old run'));
    expect(RefreshAllData::running())->toBe('r1');

    (new RefreshAllData('r1'))->failed(new RuntimeException('worker died'));
    expect(RefreshAllData::running())->toBeNull();
});

it('expires the running mark so a dead worker does not lock the button', function () {
    RefreshAllData::start('r1');

    Carbon::setTestNow(Carbon::now()->addSeconds(RefreshAllData::TIMEOUT_SECONDS + 1));

    expect(RefreshAllData::running())->toBeNull();
});
