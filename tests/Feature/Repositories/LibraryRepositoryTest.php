<?php

use App\Models\Chapter;
use App\Models\Collection as LibraryEntry;
use App\Models\Manga;
use App\Models\ReadingProgress;
use App\Models\StoredChapter;
use App\Repositories\ContinueReading;
use App\Repositories\LibraryChapter;
use App\Repositories\LibraryRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

const LIB_GROUP_A = 'aaaaaaaa-0000-4000-8000-000000000000';
const LIB_GROUP_B = 'bbbbbbbb-0000-4000-8000-000000000000';

beforeEach(function () {
    Carbon::setTestNow('2026-10-24 12:00:00');
    $this->library = app(LibraryRepository::class);
});

/** Readable fake uuid: `libId('m', 1)` = 'm0000000-0000-4000-8000-000000000001'. */
function libId(string $kind, int $n): string
{
    return $kind.sprintf('0000000-0000-4000-8000-%012d', $n);
}

function libManga(int $n): Manga
{
    return Manga::query()->create([
        'id' => libId('m', $n), 'title' => ['en' => "Series {$n}"], 'alt_titles' => [], 'description' => [],
        'content_rating' => 'safe', 'available_languages' => ['en', 'es-la'], 'tag_ids' => [], 'fetched_at' => Carbon::now(),
    ]);
}

function libChapter(int $n, int $manga, ?string $number, array $extra = []): Chapter
{
    return Chapter::query()->create([
        'id' => libId('c', $n), 'manga_id' => libId('m', $manga), 'number' => $number, 'language' => 'es-la',
        'group_id' => LIB_GROUP_A, 'group_name' => 'Group A', 'pages' => 20, 'fetched_at' => Carbon::now(),
        ...$extra,
    ]);
}

/** "number/group letter[*]" per chapter, `*` = otherGroup, sections joined by `|` with their volume. */
function libSections(array $sections): string
{
    return collect($sections)->map(fn (array $s) => ($s['volume'] ?? 'none').': '.$s['chapters']
        ->map(fn (LibraryChapter $c) => ($c->chapter->number ?? 'Oneshot').'/'.$c->chapter->group_name[-1].($c->otherGroup ? '*' : ''))
        ->implode(' '))->implode(' | ');
}

/** "manga n: chapter number" per tile. */
function libTiles(iterable $tiles): array
{
    return collect($tiles)->map(fn (ContinueReading $t) => $t->manga->title['en'].': '.$t->chapter->number.($t->progress === null ? ' (next)' : ''))->all();
}

it('puts a series in the Library on the first page read, preferred group from that chapter', function () {
    libManga(1);
    libChapter(1, 1, '1');
    libChapter(2, 1, '2', ['group_id' => LIB_GROUP_B, 'group_name' => 'Group B']);

    expect($this->library->has(libId('m', 1)))->toBeFalse();

    $this->library->saveProgress(libId('c', 1), 3, 20);
    $this->library->saveProgress(libId('c', 2), 0, 20);

    expect($this->library->has(libId('m', 1)))->toBeTrue()
        ->and($this->library->preferredGroup(libId('m', 1)))->toBe(LIB_GROUP_A)
        ->and(LibraryEntry::query()->count())->toBe(1)
        ->and($this->library->progressFor(libId('c', 1)))
        ->page->toBe(3)->page_count->toBe(20)->manga_id->toBe(libId('m', 1))->finished_at->toBeNull();
});

it('marks a chapter finished at the last page and keeps it finished when read again', function () {
    libManga(1);
    libChapter(1, 1, '1');

    $this->library->saveProgress(libId('c', 1), 19, 20);
    Carbon::setTestNow('2026-10-25 08:00:00');
    $again = $this->library->saveProgress(libId('c', 1), 2, 20);

    expect($again->page)->toBe(2)
        ->and($this->library->progressFor(libId('c', 1))->finished_at?->toDateTimeString())->toBe('2026-10-24 12:00:00')
        ->and($again->updated_at->toDateTimeString())->toBe('2026-10-25 08:00:00');
});

it('rejects a page outside the chapter', function (int $page, int $count) {
    libManga(1);
    libChapter(1, 1, '1');

    expect(fn () => $this->library->saveProgress(libId('c', 1), $page, $count))->toThrow(InvalidArgumentException::class);
    expect(ReadingProgress::query()->count())->toBe(0)->and(LibraryEntry::query()->count())->toBe(0);
})->with([[20, 20], [-1, 20], [0, 0]]);

it('keeps a preferred group the user changed, and favorites add the series', function () {
    libManga(1);
    libChapter(1, 1, '1');

    $this->library->setFavorite(libId('m', 1), true);
    $this->library->setPreferredGroup(libId('m', 1), LIB_GROUP_B);
    $this->library->saveProgress(libId('c', 1), 0, 20);

    expect(LibraryEntry::query()->find(libId('m', 1)))->favorite->toBeTrue()->preferred_group_id->toBe(LIB_GROUP_B);
});

it('lists the Library last read first, then by date added, with filter chips', function () {
    foreach ([1, 2, 3, 4, 5, 6] as $n) {
        libManga($n);
        libChapter($n, $n, '1');
    }
    libChapter(62, 6, '2');

    $this->library->add(libId('m', 1));                     // 12:00, never read
    Carbon::setTestNow('2026-10-24 13:00:00');
    $this->library->saveProgress(libId('c', 2), 0, 20);     // read 13:00
    Carbon::setTestNow('2026-10-24 14:00:00');
    $this->library->setFavorite(libId('m', 3), true);       // added 14:00, never read
    Carbon::setTestNow('2026-10-24 15:00:00');
    $this->library->saveProgress(libId('c', 4), 0, 20);     // read 15:00
    Carbon::setTestNow('2026-10-24 16:00:00');
    $this->library->saveProgress(libId('c', 1), 0, 20);     // added first, read 16:00
    Carbon::setTestNow('2026-10-24 16:30:00');
    $this->library->saveProgress(libId('c', 5), 19, 20);    // finished, nothing after: caught up
    Carbon::setTestNow('2026-10-24 17:00:00');
    $this->library->saveProgress(libId('c', 6), 19, 20);    // finished, chapter 2 still to read
    StoredChapter::query()->insert([
        ['chapter_id' => libId('c', 1), 'tier' => 'icloud', 'quality' => 'data-saver', 'status' => 'done'],
        ['chapter_id' => libId('c', 2), 'tier' => 'cache', 'quality' => 'data-saver', 'status' => 'done'],
        ['chapter_id' => libId('c', 4), 'tier' => 'local', 'quality' => 'data-saver', 'status' => 'downloading'],
    ]);

    $titles = fn (string $filter) => $this->library->collections($filter)->map(fn (Manga $m) => $m->title['en'])->all();

    expect($titles('all'))->toBe(['Series 6', 'Series 5', 'Series 1', 'Series 4', 'Series 3', 'Series 2'])
        // Reading = any series the reader has opened a chapter of. Caught-up series stay here
        // ("I read this") even though Home's Continue-reading row leaves them out.
        ->and($titles('reading'))->toBe(['Series 6', 'Series 5', 'Series 1', 'Series 4', 'Series 2'])
        // Cache tier and unfinished downloads don't count as downloaded.
        ->and($titles('downloaded'))->toBe(['Series 1'])
        ->and($titles('favorites'))->toBe(['Series 3'])
        ->and(fn () => $this->library->collections('unread'))->toThrow(InvalidArgumentException::class);
});

it('shows one upload per number on the Collection screen, preferred group first', function () {
    libManga(1);
    // B sorts after A by name, so picking the preferred group isn't reading order by accident.
    $this->library->setPreferredGroup(libId('m', 1), LIB_GROUP_B);
    $b = ['group_id' => LIB_GROUP_B, 'group_name' => 'Group B'];
    $c = ['group_id' => 'cccccccc-0000-4000-8000-000000000000', 'group_name' => 'Group C'];

    libChapter(1, 1, '1', ['volume' => '1']);
    libChapter(2, 1, '1', ['volume' => '1', ...$b]);
    libChapter(3, 1, '2', ['volume' => '1']);                         // only A has 2
    libChapter(4, 1, '3', $c);                                        // being read, no volume …
    libChapter(5, 1, '3', ['volume' => '1']);                         // … A's upload of 3 is in volume 1
    libChapter(6, 1, '4', [...$c, 'fetched_at' => null]);             // gone from the feed, stored
    libChapter(7, 1, '4');
    libChapter(8, 1, null);                                           // two oneshots stay apart
    libChapter(9, 1, null, $b);
    libChapter(10, 1, '1', ['language' => 'en']);                     // other language
    StoredChapter::query()->insert(['chapter_id' => libId('c', 6), 'tier' => 'local', 'quality' => 'data-saver', 'status' => 'done']);
    $this->library->saveProgress(libId('c', 4), 5, 20);

    // 3: the upload being read (C) wins over A, and goes into volume 1 because A's upload has it.
    // 4: the stored one (C), kept with fetched_at = null.
    expect(libSections($this->library->sections(libId('m', 1), 'es-la')))
        ->toBe('1: 1/B 2/A* 3/C* | none: 4/C* Oneshot/A* Oneshot/B')
        ->and($this->library->sections(libId('m', 1), 'es-la')[0]['chapters'][2]->progress->page)->toBe(5);
});

it('picks the first upload in reading order without a preferred group, unmarked', function () {
    libManga(1);
    libChapter(1, 1, '1', ['group_id' => LIB_GROUP_B, 'group_name' => 'Group B']);
    libChapter(2, 1, '1');

    expect(libSections($this->library->sections(libId('m', 1), 'es-la')))->toBe('none: 1/A');
});

it('continues reading last read first, one tile per series, finished chapters move on (Continue reading order)', function () {
    foreach ([1, 2, 3, 4] as $n) {
        libManga($n);
    }
    libChapter(11, 1, '1');
    libChapter(12, 1, '2');
    libChapter(13, 1, '2', ['group_id' => LIB_GROUP_B, 'group_name' => 'Group B']);
    libChapter(21, 2, '1');
    libChapter(22, 2, '2');
    libChapter(31, 3, '1');
    libChapter(41, 4, '7');

    $this->library->saveProgress(libId('c', 21), 4, 20);   // 12:00 series 2 ch 1, then ch 2 later
    Carbon::setTestNow('2026-10-24 13:00:00');
    $this->library->saveProgress(libId('c', 11), 19, 20);  // 13:00 series 1 ch 1 finished → ch 2 of group A
    Carbon::setTestNow('2026-10-24 14:00:00');
    $this->library->saveProgress(libId('c', 31), 19, 20);  // 14:00 series 3 finished, nothing after → left out
    Carbon::setTestNow('2026-10-24 15:00:00');
    $this->library->saveProgress(libId('c', 22), 7, 20);   // 15:00 series 2 ch 2
    Carbon::setTestNow('2026-10-24 16:00:00');
    $this->library->saveProgress(libId('c', 41), 1, 20);   // 16:00 series 4

    $tiles = $this->library->continueReading();

    expect(libTiles($tiles))->toBe(['Series 4: 7', 'Series 2: 2', 'Series 1: 2 (next)'])
        ->and($tiles[2]->chapter->id)->toBe(libId('c', 12))
        ->and(libTiles($this->library->continueReading(2)))->toBe(['Series 4: 7', 'Series 2: 2']);
});

it('resumes a series on the last chapter read, at its saved page, or on the next chapter once finished', function () {
    libManga(1);
    libChapter(1, 1, '1');
    libChapter(2, 1, '2');

    expect($this->library->resume(libId('m', 1)))->toBeNull();

    $this->library->saveProgress(libId('c', 2), 6, 20);
    Carbon::setTestNow('2026-10-24 13:00:00');
    $this->library->saveProgress(libId('c', 1), 3, 20);    // went back to chapter 1 last

    $resume = $this->library->resume(libId('m', 1));
    expect($resume->chapter->id)->toBe(libId('c', 1))->and($resume->progress->page)->toBe(3);

    Carbon::setTestNow('2026-10-24 14:00:00');
    $this->library->saveProgress(libId('c', 1), 19, 20);   // finished → chapter 2, where it was left

    $resume = $this->library->resume(libId('m', 1));
    expect($resume->chapter->id)->toBe(libId('c', 2))->and($resume->progress->page)->toBe(6);

    Carbon::setTestNow('2026-10-24 15:00:00');
    $this->library->saveProgress(libId('c', 2), 19, 20);   // caught up
    expect($this->library->resume(libId('m', 1)))->toBeNull();

    libChapter(3, 1, '3');                                 // a new chapter in the feed
    $resume = $this->library->resume(libId('m', 1));
    expect($resume->chapter->id)->toBe(libId('c', 3))->and($resume->progress)->toBeNull();
});

it('opens the resume bar on the chapter read last, across series, at its saved page', function () {
    libManga(1);
    libManga(2);
    libChapter(11, 1, '1');
    libChapter(21, 2, '1');

    expect($this->library->lastOpened())->toBeNull();

    $this->library->saveProgress(libId('c', 21), 8, 20);   // 12:00 series 2
    Carbon::setTestNow('2026-10-24 13:00:00');
    $this->library->saveProgress(libId('c', 11), 4, 20);   // 13:00 series 1, read last

    $last = $this->library->lastOpened();
    expect($last->manga->id)->toBe(libId('m', 1))
        ->and($last->chapter->id)->toBe(libId('c', 11))
        ->and($last->progress->page)->toBe(4);

    Carbon::setTestNow('2026-10-24 14:00:00');
    $this->library->saveProgress(libId('c', 21), 9, 20);   // back to series 2

    expect($this->library->lastOpened())->chapter->id->toBe(libId('c', 21))->progress->page->toBe(9);
});

it('breaks a tie on the time read by chapter id, as resume() does', function () {
    libManga(1);
    libManga(2);
    libChapter(22, 2, '1');
    libChapter(11, 1, '1');

    // Same second; the larger id is saved first so the table's own order isn't the answer.
    $this->library->saveProgress(libId('c', 22), 3, 20);
    $this->library->saveProgress(libId('c', 11), 5, 20);
    ReadingProgress::query()->update(['updated_at' => Carbon::now()]);

    expect($this->library->lastOpened()->chapter->id)->toBe(libId('c', 11));
});

it('moves the resume bar to the next chapter once the last one read is finished, on its own saved page if it has one', function () {
    libManga(1);
    libChapter(1, 1, '1');
    libChapter(2, 1, '2');
    libChapter(3, 1, '2', ['language' => 'en']);          // same number, other language
    libChapter(4, 1, '3');

    $this->library->saveProgress(libId('c', 1), 19, 20);  // finished, chapter 2 never opened

    $last = $this->library->lastOpened();
    expect($last->chapter->id)->toBe(libId('c', 2))->and($last->progress)->toBeNull();

    $this->library->saveProgress(libId('c', 4), 6, 20);   // looked at chapter 3 …
    Carbon::setTestNow('2026-10-24 13:00:00');
    $this->library->saveProgress(libId('c', 2), 19, 20);  // … then finished chapter 2

    $last = $this->library->lastOpened();
    expect($last->chapter->id)->toBe(libId('c', 4))->and($last->progress->page)->toBe(6);
});

it('hides the resume bar when the series read last is caught up, even with another series mid-read', function () {
    libManga(1);
    libManga(2);
    libChapter(11, 1, '1');
    libChapter(21, 2, '1');
    libChapter(22, 2, '2');

    $this->library->saveProgress(libId('c', 21), 5, 20);   // 12:00 series 2 mid-read
    Carbon::setTestNow('2026-10-24 13:00:00');
    $this->library->saveProgress(libId('c', 11), 19, 20);  // 13:00 series 1 finished, nothing after

    expect($this->library->lastOpened())->toBeNull()
        ->and(libTiles($this->library->continueReading()))->toBe(['Series 2: 1']);
});

it('hides the resume bar when the chapter or series read last is gone', function (string $gone) {
    libManga(1);
    libChapter(1, 1, '1');
    $this->library->saveProgress(libId('c', 1), 0, 20);

    $gone === 'chapter'
        ? Chapter::query()->whereKey(libId('c', 1))->delete()
        : Manga::query()->whereKey(libId('m', 1))->delete();

    expect($this->library->lastOpened())->toBeNull();
})->with(['chapter', 'manga']);

it('looks up an unfinished last chapter in a few queries (it runs on every tab render)', function () {
    libManga(1);
    foreach (range(1, 30) as $n) {
        libChapter($n, 1, (string) $n);
    }
    $this->library->saveProgress(libId('c', 7), 3, 20);

    DB::enableQueryLog();
    $last = $this->library->lastOpened();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($last->chapter->id)->toBe(libId('c', 7))->and($queries)->toBeLessThanOrEqual(4);
});

it('leaves a tile out when its chapter or manga row is missing', function () {
    libManga(1);
    libChapter(1, 1, '1');
    $this->library->saveProgress(libId('c', 1), 0, 20);
    Chapter::query()->whereKey(libId('c', 1))->delete();

    expect($this->library->continueReading())->toBeEmpty();
});
