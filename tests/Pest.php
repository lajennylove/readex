<?php

use App\Repositories\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Agnostic-source refactor (feature/0035): ReaDex no longer ships a baked-in
        // source URL — every HTTP call reads it via App\Sources\ActiveSource. Tests
        // seed a known fake URL matching the fixtures tests have historically used,
        // so Http::fake matchers keep working unchanged. Individual tests can
        // overwrite via SettingsRepository.
        app(SettingsRepository::class)->setSourceUrl('https://api.source.test/api');
    })
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit', 'Integration');
