<?php

declare(strict_types=1);

use App\Adapters\Http\RateLimit\Priority;
use App\Downloads\DownloadQueue;
use App\Downloads\QueueState;
use App\Models\Chapter;
use App\Models\Download;
use App\Repositories\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Native\Mobile\AsyncTask;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Testing\Native;
use Tests\Support\Covers;

beforeEach(function () {
    Carbon::setTestNow('2026-10-29 12:00:00');
    Covers::useTempCaches();
    Queue::fake();
    AsyncTask::fake();
    app(SettingsRepository::class)->setLanguage('en');
});

afterEach(fn () => AsyncTask::clearFake());

function dtId(string $kind, int $n): string
{
    return $kind.'0000000-3333-4000-8000-'.sprintf('%012d', $n);
}

function dtChapter(int $n, string $mangaId, ?string $number = '1'): Chapter
{
    return Chapter::query()->create([
        'id' => dtId('c', $n), 'manga_id' => $mangaId, 'number' => $number, 'language' => 'en',
        'group_id' => 'dt-group0-0000-4000-8000-000000000000', 'group_name' => 'DT Group',
        'pages' => 20, 'fetched_at' => Carbon::now(),
    ]);
}

function dtInsertRow(string $chapterId, string $mangaId, QueueState $state, int $position = 1): void
{
    Download::query()->insert([
        'chapter_id' => $chapterId,
        'manga_id' => $mangaId,
        'batch' => null,
        'position' => $position,
        'priority' => Priority::Bulk->value,
        'state' => $state->value,
        'started_at' => $state === QueueState::Downloading ? Carbon::now() : null,
        'finished_at' => null,
        'created_at' => Carbon::now(),
    ]);
}

it('shows the empty placeholder when nothing is pending', function () {
    Native::visit('/downloads')
        ->assertSee('No downloads queued')
        ->assertSee('Chapters you queue from a series');
});

it('shows a Queued row with its chip plus Pause and Cancel actions, grouped under the series title', function () {
    Covers::manga(dtId('m', 1), title: 'Queued Series');
    dtChapter(1, dtId('m', 1), '7');
    dtInsertRow(dtId('c', 1), dtId('m', 1), QueueState::Queued);

    Native::visit('/downloads')
        ->assertSee('Queued Series')
        ->assertSee('Ch. 7')
        ->assertSee('Queued')
        ->assertSee('Pause Ch. 7')
        ->assertSee('Cancel Ch. 7');
});

it('shows a Downloading row with Pause and Cancel actions', function () {
    Covers::manga(dtId('m', 2), title: 'DL Series');
    dtChapter(2, dtId('m', 2), '2');
    dtInsertRow(dtId('c', 2), dtId('m', 2), QueueState::Downloading);

    Native::visit('/downloads')
        ->assertSee('Downloading')
        ->assertSee('Pause Ch. 2')
        ->assertSee('Cancel Ch. 2');
});

it('renders the per-chapter percentage on a Downloading row when stored_chapters has progress', function () {
    Covers::manga(dtId('m', 70), title: 'Progress Series');
    dtChapter(70, dtId('m', 70), '7');
    dtInsertRow(dtId('c', 70), dtId('m', 70), QueueState::Downloading);

    DB::table('stored_chapters')->insert([
        'chapter_id' => dtId('c', 70),
        'tier' => 'local',
        'quality' => 'data',
        'status' => 'begun',
        'pages_done' => 3,
        'page_count' => 10,
        'bytes' => 0,
    ]);

    $visit = Native::visit('/downloads')
        ->assertSee('Downloading · 30%');

    // a11y also carries the percent for screen readers.
    expect(json_encode($visit->tree()))->toContain('Ch. 7, Downloading, 30 percent');
});

it('falls back to a plain Downloading chip when no stored_chapters row exists yet', function () {
    Covers::manga(dtId('m', 71), title: 'Pre-Progress Series');
    dtChapter(71, dtId('m', 71), '8');
    dtInsertRow(dtId('c', 71), dtId('m', 71), QueueState::Downloading);
    // Deliberately no stored_chapters row — the join misses and we fall back.

    Native::visit('/downloads')
        ->assertSee('Downloading')
        ->assertDontSee('Downloading ·');
});

it('shows a Paused row with Resume and Cancel actions', function () {
    Covers::manga(dtId('m', 3), title: 'Paused Series');
    dtChapter(3, dtId('m', 3), '3');
    dtInsertRow(dtId('c', 3), dtId('m', 3), QueueState::Paused);

    Native::visit('/downloads')
        ->assertSee('Paused')
        ->assertSee('Resume Ch. 3')
        ->assertSee('Cancel Ch. 3');
});

it('shows a Canceling row disabled with no action pressables', function () {
    Covers::manga(dtId('m', 4), title: 'Cancel Series');
    dtChapter(4, dtId('m', 4), '4');
    dtInsertRow(dtId('c', 4), dtId('m', 4), QueueState::Canceling);

    Native::visit('/downloads')
        ->assertSee('Canceling')
        ->assertDontSee('Pause Ch. 4')
        ->assertDontSee('Cancel Ch. 4')
        ->assertDontSee('Resume Ch. 4');
});

it('pausing a Queued row flips it to Paused on the next render', function () {
    Covers::manga(dtId('m', 5), title: 'S');
    dtChapter(5, dtId('m', 5), '5');
    dtInsertRow(dtId('c', 5), dtId('m', 5), QueueState::Queued);

    Native::visit('/downloads')->call('pause', dtId('c', 5));

    expect(Download::query()->whereKey(dtId('c', 5))->first()->state)->toBe(QueueState::Paused);
    Native::visit('/downloads')
        ->assertSee('Paused')
        ->assertSee('Resume Ch. 5');
});

it('resuming a Paused row flips it to Queued on the next render', function () {
    Covers::manga(dtId('m', 6), title: 'S');
    dtChapter(6, dtId('m', 6), '6');
    dtInsertRow(dtId('c', 6), dtId('m', 6), QueueState::Paused);

    Native::visit('/downloads')->call('resume', dtId('c', 6));

    expect(Download::query()->whereKey(dtId('c', 6))->first()->state)->toBe(QueueState::Queued);
    Native::visit('/downloads')
        ->assertSee('Queued')
        ->assertSee('Pause Ch. 6');
});

it('cancelling a Queued row deletes it outright and it no longer renders', function () {
    Covers::manga(dtId('m', 7), title: 'Gone');
    dtChapter(7, dtId('m', 7), '7');
    dtInsertRow(dtId('c', 7), dtId('m', 7), QueueState::Queued);

    Native::visit('/downloads')->call('cancel', dtId('c', 7));

    expect(Download::query()->whereKey(dtId('c', 7))->exists())->toBeFalse();
    Native::visit('/downloads')
        ->assertSee('No downloads queued')
        ->assertDontSee('Gone');
});

it('groups chapters by series in queue order and renders the chapter count under each header', function () {
    $mangaA = dtId('m', 20);
    $mangaB = dtId('m', 21);
    Covers::manga($mangaA, title: 'Alpha Series');
    Covers::manga($mangaB, title: 'Beta Series');

    // Interleaved on purpose: A/B/A. Group order should follow earliest position = A first.
    dtChapter(20, $mangaA, '1');
    dtChapter(21, $mangaB, '7');
    dtChapter(22, $mangaA, '2');
    dtInsertRow(dtId('c', 20), $mangaA, QueueState::Queued, position: 1);
    dtInsertRow(dtId('c', 21), $mangaB, QueueState::Queued, position: 2);
    dtInsertRow(dtId('c', 22), $mangaA, QueueState::Queued, position: 3);

    $visit = Native::visit('/downloads')
        ->assertSee('Alpha Series')
        ->assertSee('Beta Series')
        ->assertSee('2 chapters')
        ->assertSee('1 chapter')
        ->assertSee('Ch. 1')
        ->assertSee('Ch. 2')
        ->assertSee('Ch. 7');

    // Group order: Alpha first (earliest queue position).
    $tree = json_encode($visit->tree());
    expect(strpos($tree, 'Alpha Series'))->toBeLessThan(strpos($tree, 'Beta Series'));
});

it('renders a cover image in the group header when the file is on disk', function () {
    $caches = Covers::useTempCaches();
    $mangaId = dtId('m', 30);
    $manga = Covers::manga($mangaId, title: 'Covered Series');
    Covers::store($caches, $manga);
    dtChapter(30, $mangaId, '4');
    dtInsertRow(dtId('c', 30), $mangaId, QueueState::Queued);

    $visit = Native::visit('/downloads')->assertSee('Covered Series');

    // Same relative-src format resume-bar + tile + manga-detail use: `storage/cache/covers/<id>/...`.
    $tree = json_encode($visit->tree());
    expect($tree)->toContain("storage\/cache\/covers\/{$mangaId}\/cover.jpg.256.jpg");
    // Skeleton overlay is present with loaded=true (matches tile/manga-detail pattern); the
    // raw placeholder icon from the previous grouping pass must no longer be in the tree.
    expect($tree)->not->toContain('book.closed');
});

it('renders N-1 dividers between N groups and none after the last', function () {
    $mangaA = dtId('m', 40);
    $mangaB = dtId('m', 41);
    $mangaC = dtId('m', 42);
    Covers::manga($mangaA, title: 'Group A');
    Covers::manga($mangaB, title: 'Group B');
    Covers::manga($mangaC, title: 'Group C');
    dtChapter(40, $mangaA, '1');
    dtChapter(41, $mangaB, '1');
    dtChapter(42, $mangaC, '1');
    dtInsertRow(dtId('c', 40), $mangaA, QueueState::Queued, position: 1);
    dtInsertRow(dtId('c', 41), $mangaB, QueueState::Queued, position: 2);
    dtInsertRow(dtId('c', 42), $mangaC, QueueState::Queued, position: 3);

    $visit = Native::visit('/downloads')
        ->assertSee('Group A')
        ->assertSee('Group B')
        ->assertSee('Group C');

    $tree = json_encode($visit->tree());
    // 3 groups → exactly 2 dividers (between A|B and B|C, none after C). The engine
    // strips native:key off `<native:divider />`, so we count the rendered type instead.
    expect(substr_count($tree, '"type":"divider"'))->toBe(2);
});

it('renders no divider when there is a single group', function () {
    Covers::manga(dtId('m', 50), title: 'Only Series');
    dtChapter(50, dtId('m', 50), '1');
    dtInsertRow(dtId('c', 50), dtId('m', 50), QueueState::Queued);

    $visit = Native::visit('/downloads')->assertSee('Only Series');

    expect(json_encode($visit->tree()))->not->toContain('"type":"divider"');
});

it('renders a Cancel-all pressable in each group header with an a11y label naming the series', function () {
    Covers::manga(dtId('m', 60), title: 'Bulk Series');
    dtChapter(60, dtId('m', 60), '1');
    dtInsertRow(dtId('c', 60), dtId('m', 60), QueueState::Queued);

    $visit = Native::visit('/downloads')->assertSee('Cancel all');
    $tree = json_encode($visit->tree());
    expect($tree)->toContain('Cancel all downloads for Bulk Series');
});

it('tapping Cancel all opens a destructive Dialog.Alert with the exact confirmation copy', function () {
    Covers::manga(dtId('m', 61), title: 'Confirm Series');
    dtChapter(61, dtId('m', 61), '1');
    dtInsertRow(dtId('c', 61), dtId('m', 61), QueueState::Queued);

    Native::visit('/downloads')
        ->call('askCancelAllForManga', dtId('m', 61), 'Confirm Series')
        ->assertNativeCalled('Dialog.Alert', fn (array $p) => $p['title'] === 'Cancel downloads?'
            && $p['message'] === 'All queued, paused, and downloading chapters for "Confirm Series" will be removed from the queue. Chapters already downloaded stay on your device.'
            && array_column($p['buttons'], 'label') === ['Cancel', 'Cancel all']
            && $p['buttons'][1]['style'] === 'destructive');
});

it('confirming the dialog wipes every non-done row for that manga and the group disappears', function () {
    $target = dtId('m', 62);
    $other = dtId('m', 63);
    Covers::manga($target, title: 'Target Series');
    Covers::manga($other, title: 'Other Series');
    dtChapter(62, $target, '1');
    dtChapter(63, $target, '2');
    dtChapter(64, $other, '1');
    dtInsertRow(dtId('c', 62), $target, QueueState::Queued, position: 1);
    dtInsertRow(dtId('c', 63), $target, QueueState::Paused, position: 2);
    dtInsertRow(dtId('c', 64), $other, QueueState::Queued, position: 3);

    Native::visit('/downloads')
        ->call('askCancelAllForManga', $target, 'Target Series')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Cancel all']);

    expect(Download::query()->where('manga_id', $target)->exists())->toBeFalse()
        ->and(Download::query()->whereKey(dtId('c', 64))->exists())->toBeTrue();

    Native::visit('/downloads')
        ->assertDontSee('Target Series')
        ->assertSee('Other Series');
});

it('cancelling the Cancel-all dialog leaves every row intact', function () {
    Covers::manga(dtId('m', 65), title: 'Keep Series');
    dtChapter(65, dtId('m', 65), '1');
    dtInsertRow(dtId('c', 65), dtId('m', 65), QueueState::Queued);

    Native::visit('/downloads')
        ->call('askCancelAllForManga', dtId('m', 65), 'Keep Series')
        ->emitNative(ButtonPressed::class, ['index' => 0, 'label' => 'Cancel']);

    expect(Download::query()->whereKey(dtId('c', 65))->exists())->toBeTrue();
});

it('never renders the comodin chapter (read_now_lane)', function () {
    Covers::manga(dtId('m', 8), title: 'Comodin Series');
    dtChapter(8, dtId('m', 8), '42');
    // Comodin: no row in downloads table, chapter_id in read_now_lane.
    DB::table('read_now_lane')->where('id', 1)->update(['chapter_id' => dtId('c', 8), 'started_at' => Carbon::now()]);

    // Guard: pending() excludes the comodin, so nothing to render.
    expect(app(DownloadQueue::class)->pending()->pluck('chapter_id')->all())->not->toContain(dtId('c', 8));

    Native::visit('/downloads')
        ->assertSee('No downloads queued')
        ->assertDontSee('Ch. 42');
});
