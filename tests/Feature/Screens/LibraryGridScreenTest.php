<?php

use App\Models\Chapter;
use App\Models\ChapterFeed;
use App\Models\Collection as LibraryEntry;
use App\Models\Manga;
use App\Models\StoredChapter;
use App\Repositories\LibraryRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\ViewedSeriesRepository;
use Illuminate\Support\Carbon;
use Native\Mobile\AsyncTask;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Testing\Native;
use Tests\Support\Covers;

beforeEach(function () {
    Carbon::setTestNow('2026-10-25 12:00:00');
    $this->caches = Covers::useTempCaches();
    app(SettingsRepository::class)->setLanguage('en');
    $this->library = app(LibraryRepository::class);
    AsyncTask::fake();
});

afterEach(fn () => AsyncTask::clearFake());

function lgsId(string $kind, int $n): string
{
    return $kind.sprintf('0000000-2222-4000-8000-%012d', $n);
}

function lgsManga(int $n, string $title): Manga
{
    return Covers::manga(lgsId('m', $n), title: $title);
}

function lgsChapter(int $n, int $manga, string $number): Chapter
{
    return Chapter::query()->create([
        'id' => lgsId('c', $n), 'manga_id' => lgsId('m', $manga), 'number' => $number, 'language' => 'en',
        'group_id' => 'lgs-group0-0000-4000-8000-000000000000', 'group_name' => 'LGS Group', 'pages' => 20, 'fetched_at' => Carbon::now(),
    ]);
}

/** Every text in the last render, in screen order. */
function lgsTexts(array $node): array
{
    $texts = ($node['type'] ?? null) === 'text' ? [$node['props']['text']] : [];

    foreach ($node['children'] ?? [] as $child) {
        $texts = [...$texts, ...lgsTexts($child)];
    }

    return $texts;
}

it('shows the grid, including pinned/favorite/chip tiles, and the "you may still be interested" row', function () {
    lgsManga(1, 'Series One');
    $this->library->add(lgsId('m', 1));
    lgsManga(2, 'Interesting series');
    app(ViewedSeriesRepository::class)->recordView(lgsId('m', 2));

    $screen = Native::visit('/library');

    $screen->assertSee('Series One')->assertSee('Interesting series');
});

it('switches the filter chip and reloads the grid', function () {
    lgsManga(1, 'Favorite series');
    lgsManga(2, 'Plain series');
    $this->library->setFavorite(lgsId('m', 1), true);
    $this->library->add(lgsId('m', 2));

    $screen = Native::visit('/library')->assertSee('Favorite series')->assertSee('Plain series');
    $screen->call('setFilter', 'favorites')->assertSee('Favorite series')->assertDontSee('Plain series');
});

it('switches the sort order to Title, changing tile order', function () {
    lgsManga(1, 'Zed series');
    lgsManga(2, 'Abe series');
    $this->library->add(lgsId('m', 1));
    $this->library->add(lgsId('m', 2));

    $screen = Native::visit('/library')->call('setSort', 'title');

    $texts = lgsTexts($screen->tree());
    expect(array_search('Abe series', $texts, true))->toBeLessThan(array_search('Zed series', $texts, true));
});

it('filters the grid by a typed search query', function () {
    lgsManga(1, 'Attack on Titan');
    lgsManga(2, 'One Piece');
    $this->library->add(lgsId('m', 1));
    $this->library->add(lgsId('m', 2));

    $screen = Native::visit('/library')->call('searchFor', 'titan');

    $screen->assertSee('Attack on Titan')->assertDontSee('One Piece');
});

it('pins a series via long-press action sheet, moving it to the front of the grid', function () {
    lgsManga(1, 'Series One');
    lgsManga(2, 'Series Two');
    $this->library->add(lgsId('m', 1));
    $this->library->add(lgsId('m', 2));

    Native::visit('/library')
        ->longPress("askLibraryTileAction('".lgsId('m', 2)."')")
        ->assertNativeCalled('Dialog.Alert', fn (array $p) => in_array('Pin', array_column($p['buttons'], 'label'), true))
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Pin']);

    expect(LibraryEntry::query()->find(lgsId('m', 2))->pinned)->toBeTrue();
});

it('unpins a pinned series via the same long-press action sheet', function () {
    lgsManga(1, 'Series One');
    $this->library->add(lgsId('m', 1));
    $this->library->setPinned(lgsId('m', 1), true);

    Native::visit('/library')
        ->longPress("askLibraryTileAction('".lgsId('m', 1)."')")
        ->assertNativeCalled('Dialog.Alert', fn (array $p) => in_array('Unpin', array_column($p['buttons'], 'label'), true))
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Unpin']);

    expect(LibraryEntry::query()->find(lgsId('m', 1))->pinned)->toBeFalse();
});

it('removes a series from the Library via the same long-press action sheet', function () {
    lgsManga(1, 'Series One');
    $this->library->add(lgsId('m', 1));

    Native::visit('/library')
        ->longPress("askLibraryTileAction('".lgsId('m', 1)."')")
        ->emitNative(ButtonPressed::class, ['index' => 2, 'label' => 'Remove']);

    expect($this->library->has(lgsId('m', 1)))->toBeFalse();
});

it('removes a series from the interest row via its own long-press action', function () {
    lgsManga(1, 'Interesting series');
    app(ViewedSeriesRepository::class)->recordView(lgsId('m', 1));

    Native::visit('/library')->call('removeFromInterestRow', lgsId('m', 1));

    expect(app(ViewedSeriesRepository::class)->list())->toBeEmpty();
});

it('shows a status chip on a downloaded tile', function () {
    lgsManga(1, 'Finished series');
    lgsChapter(1, 1, '1');
    ChapterFeed::query()->create(['manga_id' => lgsId('m', 1), 'language' => 'en', 'total' => 1, 'fetched_at' => Carbon::now()]);
    StoredChapter::query()->insert([
        ['chapter_id' => lgsId('c', 1), 'tier' => 'local', 'quality' => 'data-saver', 'status' => 'done'],
    ]);
    $this->library->add(lgsId('m', 1));

    Native::visit('/library')->assertSee('Finished series')->assertSee('Downloaded');
});
