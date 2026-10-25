<?php

use App\NativeComponents\Concerns\RefreshesData;
use Native\Mobile\Platform;

afterEach(function () {
    Platform::set(null);
});

it('polls every 3 seconds on iOS', function () {
    Platform::set(Platform::IOS);

    expect(RefreshesData::pollIntervalMs())->toBe(3000);
});

it('polls every 15 seconds on Android so e-ink devices aren\'t starved', function () {
    Platform::set(Platform::ANDROID);

    expect(RefreshesData::pollIntervalMs())->toBe(15_000);
});

it('respects a config override for the Android cadence', function () {
    config()->set('readex.refreshes_data.android_poll_ms', 20_000);
    Platform::set(Platform::ANDROID);

    expect(RefreshesData::pollIntervalMs())->toBe(20_000);
});

it('respects a config override for the iOS cadence', function () {
    config()->set('readex.refreshes_data.ios_poll_ms', 5000);
    Platform::set(Platform::IOS);

    expect(RefreshesData::pollIntervalMs())->toBe(5000);
});

it('falls back to iOS cadence when the platform is unknown', function () {
    Platform::set(null);

    expect(RefreshesData::pollIntervalMs())->toBe(3000);
});
