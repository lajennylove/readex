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

    expect(json_encode($visit->tree()))->toContain("storage\/cache\/covers\/{$mangaId}\/cover.jpg.256.jpg");
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
