<?php

declare(strict_types=1);

use App\Repositories\SettingsRepository;
use App\Sources\ActiveSource;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('appends the stored api suffix to the base URL (default /api)', function () {
    app(SettingsRepository::class)->setSourceUrl('https://api.example.test');

    $active = app(ActiveSource::class);

    expect($active->baseUrl())->toBe('https://api.example.test')
        ->and($active->apiUrl())->toBe('https://api.example.test/api')
        ->and($active->coversUrl())->toBe('https://api.example.test/covers')
        ->and($active->reportUrl())->toBe('https://api.example.test/report');
});

it('appends no suffix after a SourceMetaProber writes an empty one (dynamic REST sources)', function () {
    $settings = app(SettingsRepository::class);
    $settings->setSourceUrl('https://api.example.test');
    $settings->setSourceApiSuffix('');

    expect(app(ActiveSource::class)->apiUrl())->toBe('https://api.example.test');
});

it('prefers the configured covers URL over the auto-composed sibling', function () {
    $settings = app(SettingsRepository::class);
    $settings->setSourceUrl('https://api.example.test');
    $settings->setCoversUrl('https://uploads.example.test/covers');

    expect(app(ActiveSource::class)->coversUrl())->toBe('https://uploads.example.test/covers');
});

it('falls back to {baseUrl}/covers after clearing the covers URL', function () {
    $settings = app(SettingsRepository::class);
    $settings->setSourceUrl('https://api.example.test');
    $settings->setCoversUrl('https://uploads.example.test/covers');
    $settings->clearCoversUrl();

    expect(app(ActiveSource::class)->coversUrl())->toBe('https://api.example.test/covers');
});

it('exposes an optional seasonalUrl, null when unset', function () {
    $settings = app(SettingsRepository::class);
    $settings->setSourceUrl('https://api.example.test');

    expect(app(ActiveSource::class)->seasonalUrl())->toBeNull();

    $settings->setSeasonalUrl('https://antsy.example.test/seasonal.json');
    expect(app(ActiveSource::class)->seasonalUrl())->toBe('https://antsy.example.test/seasonal.json');

    $settings->clearSeasonalUrl();
    expect(app(ActiveSource::class)->seasonalUrl())->toBeNull();
});
