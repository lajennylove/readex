<?php

declare(strict_types=1);

use App\Adapters\Http\RateLimit\Priority;
use App\Covers\CoverStore;
use App\Storage\Exceptions\DiskSpaceLow;
use Illuminate\Support\Facades\Http;
use Tests\Support\Covers;

require_once __DIR__.'/../../Unit/Storage/DiskSpaceGuardFreeSpaceStub.php';

/**
 * Write-skips-when-low-space behaviour (incident 2026-10-25, guardrail item 3): CoverStore is
 * one of the real call sites DiskSpaceGuard protects. Faking disk_free_space() below the
 * configured minimum (via the namespaced override in DiskSpaceGuardFreeSpaceStub.php) proves the
 * guard actually stops the write instead of only being unit-tested in isolation.
 */
beforeEach(function () {
    $this->caches = Covers::useTempCaches();
    $this->store = app(CoverStore::class);
    config(['readex.storage.min_free_bytes' => 100_000_000]);
});

afterEach(function () {
    unset($GLOBALS['__readex_fake_disk_free_space']);
});

it('throws DiskSpaceLow and writes nothing when the device is nearly full', function () {
    $GLOBALS['__readex_fake_disk_free_space'] = 50_000_000;
    Http::fake(['api.source.test/covers/*' => Http::response(Covers::jpeg(), 200, ['Content-Type' => 'image/jpeg'])]);
    $manga = Covers::manga('m1');

    expect(fn () => $this->store->download($manga, Priority::Interactive))->toThrow(DiskSpaceLow::class);

    Http::assertNothingSent();
    expect(glob("{$this->caches}/covers/m1/*"))->toBe([]);
});

it('downloads normally once free space is back above the minimum', function () {
    $GLOBALS['__readex_fake_disk_free_space'] = 500_000_000;
    Http::fake(['api.source.test/covers/*' => Http::response(Covers::jpeg(), 200, ['Content-Type' => 'image/jpeg'])]);
    $manga = Covers::manga('m1');

    expect($this->store->download($manga, Priority::Interactive))->toBeTrue();

    Http::assertSentCount(1);
});

it('never checks free space for a cover already on disk (no redundant syscall on the hot path)', function () {
    $GLOBALS['__readex_fake_disk_free_space'] = 50_000_000;
    $manga = Covers::manga('m1');
    Covers::store($this->caches, $manga);

    expect($this->store->download($manga, Priority::Interactive))->toBeTrue();
});
