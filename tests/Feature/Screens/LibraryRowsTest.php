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

function lrsId(string $kind, int $n): string
{
    return $kind.sprintf('0000000-2222-4000-8000-%012d', $n);
}

function lrsManga(int $n, string $title): Manga
{
    return Covers::manga(lrsId('m', $n), title: $title);
}

function lrsChapter(int $n, int $manga, string $number): Chapter
{
    return Chapter::query()->create([
        'id' => lrsId('c', $n), 'manga_id' => lrsId('m', $manga), 'number' => $number, 'language' => 'en',
        'group_id' => 'lrs-group0-0000-4000-8000-000000000000', 'group_name' => 'LRS Group', 'pages' => 20, 'fetched_at' => Carbon::now(),
    ]);
}

it('shows a favorited series under the Favorites row, and interest tiles under their row', function () {
    lrsManga(1, 'Favorite series');
    $this->library->setFavorite(lrsId('m', 1), true);
    lrsManga(2, 'Interesting series');
    app(ViewedSeriesRepository::class)->recordView(lrsId('m', 2));

    Native::visit('/library')
        ->assertSee('Favorites')
        ->assertSee('Favorite series')
        ->assertSee('You may still be interested')
        ->assertSee('Interesting series');
});

it('pins a series via the long-press action sheet', function () {
    lrsManga(1, 'Series One');
    $this->library->setFavorite(lrsId('m', 1), true);

    Native::visit('/library')
        ->longPress("askLibraryTileAction('".lrsId('m', 1)."')")
        ->assertNativeCalled('Dialog.Alert', fn (array $p) => in_array('Pin', array_column($p['buttons'], 'label'), true))
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Pin']);

    expect(LibraryEntry::query()->find(lrsId('m', 1))->pinned)->toBeTrue();
});

it('unpins a pinned series via the same long-press action sheet', function () {
    lrsManga(1, 'Series One');
    $this->library->setFavorite(lrsId('m', 1), true);
    $this->library->setPinned(lrsId('m', 1), true);

    Native::visit('/library')
        ->longPress("askLibraryTileAction('".lrsId('m', 1)."')")
        ->assertNativeCalled('Dialog.Alert', fn (array $p) => in_array('Unpin', array_column($p['buttons'], 'label'), true))
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Unpin']);

    expect(LibraryEntry::query()->find(lrsId('m', 1))->pinned)->toBeFalse();
});

it('removes a series from the Library via the same long-press action sheet', function () {
    lrsManga(1, 'Series One');
    $this->library->setFavorite(lrsId('m', 1), true);

    Native::visit('/library')
        ->longPress("askLibraryTileAction('".lrsId('m', 1)."')")
        ->emitNative(ButtonPressed::class, ['index' => 2, 'label' => 'Remove']);

    expect($this->library->has(lrsId('m', 1)))->toBeFalse();
});

it('long-pressing an interest-row tile asks before removing, and confirming removes it', function () {
    lrsManga(1, 'Interesting series');
    app(ViewedSeriesRepository::class)->recordView(lrsId('m', 1));

    Native::visit('/library')
        ->longPress("askRemoveFromInterestRow('".lrsId('m', 1)."')")
        ->assertNativeCalled('Dialog.Alert', fn (array $p) => $p['title'] === 'Remove from your interests?'
            && array_column($p['buttons'], 'label') === ['Cancel', 'Remove'])
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Remove']);

    expect(app(ViewedSeriesRepository::class)->list())->toBeEmpty();
});

it('cancelling the prompt leaves the series in the interest row', function () {
    lrsManga(1, 'Interesting series');
    app(ViewedSeriesRepository::class)->recordView(lrsId('m', 1));

    Native::visit('/library')
        ->longPress("askRemoveFromInterestRow('".lrsId('m', 1)."')")
        ->emitNative(ButtonPressed::class, ['index' => 0, 'label' => 'Cancel']);

    expect(app(ViewedSeriesRepository::class)->list())->not->toBeEmpty();
});

it('includes the current resume-bar series in the Reading row so it is still visible in the full list', function () {
    lrsManga(1, 'In-progress series');
    lrsChapter(1, 1, '1');
    $this->library->add(lrsId('m', 1));
    $this->library->saveProgress(lrsId('c', 1), 3, 20);

    Native::visit('/library')
        ->assertSee('Reading')
        ->assertSee('In-progress series');
});

it('shows a downloaded series under the Downloaded row with its status chip', function () {
    lrsManga(1, 'Finished series');
    lrsChapter(1, 1, '1');
    ChapterFeed::query()->create(['manga_id' => lrsId('m', 1), 'language' => 'en', 'total' => 1, 'fetched_at' => Carbon::now()]);
    StoredChapter::query()->insert([
        ['chapter_id' => lrsId('c', 1), 'tier' => 'local', 'quality' => 'data-saver', 'status' => 'done'],
    ]);
    $this->library->add(lrsId('m', 1));

    Native::visit('/library')
        ->assertSee('Downloaded')
        ->assertSee('Finished series');
});
