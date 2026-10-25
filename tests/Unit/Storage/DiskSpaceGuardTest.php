<?php

declare(strict_types=1);

use App\Storage\DiskSpaceGuard;
use App\Storage\Exceptions\DiskSpaceLow;
use App\Storage\LocalStorageBridge;

require_once __DIR__.'/DiskSpaceGuardFreeSpaceStub.php';

/**
 * DiskSpaceGuard (incident 2026-10-25): the overnight disk-fill incident had no guard at all
 * between "write a file" and "the device is full". These tests fake disk_free_space() crossing
 * the configured threshold via a namespaced override (see DiskSpaceGuardFreeSpaceStub.php) so
 * the behaviour is exercised without needing an actually-full filesystem.
 */
beforeEach(function () {
    unset($GLOBALS['__readex_fake_disk_free_space']);
    config(['readex.storage.min_free_bytes' => 100_000_000]);
    $this->guard = new DiskSpaceGuard(new LocalStorageBridge(sys_get_temp_dir().'/readex-diskspace-'.bin2hex(random_bytes(4))));
});

afterEach(function () {
    unset($GLOBALS['__readex_fake_disk_free_space']);
});

it('reports the free bytes disk_free_space() returns for the caches path', function () {
    $GLOBALS['__readex_fake_disk_free_space'] = 500_000_000;

    expect($this->guard->freeBytes())->toBe(500_000_000);
});

it('is not low with comfortable free space', function () {
    $GLOBALS['__readex_fake_disk_free_space'] = 500_000_000;

    expect($this->guard->isLow())->toBeFalse();
    expect(fn () => $this->guard->ensure())->not->toThrow(DiskSpaceLow::class);
});

it('is low once free space drops to the configured minimum', function () {
    $GLOBALS['__readex_fake_disk_free_space'] = 100_000_000;

    expect($this->guard->isLow())->toBeTrue();
});

it('is low below the configured minimum', function () {
    $GLOBALS['__readex_fake_disk_free_space'] = 1_000;

    expect($this->guard->isLow())->toBeTrue();
});

it('throws DiskSpaceLow from ensure() once free space is at or below the minimum', function () {
    $GLOBALS['__readex_fake_disk_free_space'] = 50_000_000;

    expect(fn () => $this->guard->ensure())->toThrow(DiskSpaceLow::class);
});

it('carries the measured free bytes and the configured minimum on the exception', function () {
    $GLOBALS['__readex_fake_disk_free_space'] = 42;
    config(['readex.storage.min_free_bytes' => 100]);

    try {
        $this->guard->ensure();
        $this->fail('expected DiskSpaceLow');
    } catch (DiskSpaceLow $e) {
        expect($e->freeBytes)->toBe(42)
            ->and($e->minFreeBytes)->toBe(100);
    }
});

it('does not throw and reports not low when disk_free_space() is unreadable', function () {
    $GLOBALS['__readex_fake_disk_free_space'] = false;

    expect($this->guard->isLow())->toBeFalse();
    expect(fn () => $this->guard->ensure())->not->toThrow(DiskSpaceLow::class);
});
