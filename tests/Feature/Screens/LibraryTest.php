<?php

use App\Models\Chapter;
use App\Repositories\LibraryRepository;
use App\Repositories\SettingsRepository;
use Illuminate\Support\Carbon;
use Tests\Support\Covers;

const LT_SOLO = 'lt000000-0000-4000-8000-000000000001';
const LT_CH1 = 'ltc00000-0000-4000-8000-000000000001';

beforeEach(function () {
    Carbon::setTestNow('2026-10-24 12:00:00');
    $this->caches = Covers::useTempCaches();
    app(SettingsRepository::class)->setLanguage('es-la');
    $this->library = app(LibraryRepository::class);

    Covers::manga(LT_SOLO, title: 'Solo Leveling');
    Chapter::query()->create([
        'id' => LT_CH1, 'manga_id' => LT_SOLO, 'volume' => null, 'number' => '1', 'title' => null,
        'language' => 'es-la', 'group_id' => 'g-lt', 'group_name' => 'Group LT', 'pages' => 20,
        'external_url' => null, 'readable_at' => Carbon::now(), 'fetched_at' => Carbon::now(),
    ]);
    $this->library->saveProgress(LT_CH1, 0, 20);
});

it('removes a series from the library repository directly, leaving its reading progress untouched', function () {
    expect($this->library->has(LT_SOLO))->toBeTrue();

    $this->library->removeFromLibrary(LT_SOLO);

    expect($this->library->has(LT_SOLO))->toBeFalse()
        ->and($this->library->progressFor(LT_CH1))->not->toBeNull();
});
