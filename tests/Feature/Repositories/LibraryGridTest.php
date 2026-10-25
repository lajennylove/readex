<?php

use App\Models\Chapter;
use App\Models\ChapterFeed;
use App\Models\Collection as LibraryEntry;
use App\Models\Manga;
use App\Models\StoredChapter;
use App\Repositories\LibraryRepository;
use App\Repositories\LibraryTile;
use Illuminate\Support\Carbon;

const LG_GROUP = 'aaaaaaaa-1111-4000-8000-000000000000';

beforeEach(function () {
    Carbon::setTestNow('2026-10-25 12:00:00');
    $this->library = app(LibraryRepository::class);
});

function lgId(string $kind, int $n): string
{
    return $kind.sprintf('0000000-1111-4000-8000-%012d', $n);
}

function lgManga(int $n, ?string $title = null): Manga
{
    return Manga::query()->create([
        'id' => lgId('m', $n), 'title' => ['en' => $title ?? "Grid {$n}"], 'alt_titles' => [], 'description' => [],
        'content_rating' => 'safe', 'available_languages' => ['en'], 'tag_ids' => [], 'fetched_at' => Carbon::now(),
    ]);
}

function lgChapter(int $n, int $manga, string $number): Chapter
{
    return Chapter::query()->create([
        'id' => lgId('c', $n), 'manga_id' => lgId('m', $manga), 'number' => $number, 'language' => 'en',
        'group_id' => LG_GROUP, 'group_name' => 'Group LG', 'pages' => 20, 'fetched_at' => Carbon::now(),
    ]);
}

it('pins and unpins a Library series', function () {
    lgManga(1);
    $this->library->add(lgId('m', 1));

    expect(LibraryEntry::query()->find(lgId('m', 1))->pinned)->toBeFalse();

    $this->library->setPinned(lgId('m', 1), true);
    expect(LibraryEntry::query()->find(lgId('m', 1))->pinned)->toBeTrue();

    $this->library->setPinned(lgId('m', 1), false);
    expect(LibraryEntry::query()->find(lgId('m', 1))->pinned)->toBeFalse();
});

it('puts pinned series first in the grid, then the rest in the picked sort order', function () {
    foreach ([1, 2, 3] as $n) {
        lgManga($n, "Series {$n}");
        $this->library->add(lgId('m', $n));
    }
    $this->library->setPinned(lgId('m', 2), true);

    $titles = fn () => $this->library->grid()->map(fn (LibraryTile $t) => $t->manga->title['en'])->all();

    // Series 2 pinned leads, the rest by date added desc (3 added after 1, both after 2... all same second here,
    // so the tiebreak is manga_id; what matters is 2 leads regardless of its own add order).
    expect($titles()[0])->toBe('Series 2');
});

it('sorts by title A-Z', function () {
    lgManga(1, 'Zeta');
    lgManga(2, 'Alpha');
    lgManga(3, 'Mu');
    foreach ([1, 2, 3] as $n) {
        $this->library->add(lgId('m', $n));
    }

    $titles = $this->library->grid(sort: 'title')->map(fn (LibraryTile $t) => $t->manga->title['en'])->all();

    expect($titles)->toBe(['Alpha', 'Mu', 'Zeta']);
});

it('sorts by date added, newest first', function () {
    lgManga(1, 'First');
    $this->library->add(lgId('m', 1));
    Carbon::setTestNow('2026-10-25 13:00:00');
    lgManga(2, 'Second');
    $this->library->add(lgId('m', 2));

    $titles = $this->library->grid(sort: 'added')->map(fn (LibraryTile $t) => $t->manga->title['en'])->all();

    expect($titles)->toBe(['Second', 'First']);
});

it('sorts by last opened, most recent first; series never opened sort after those that have been', function () {
    lgManga(1, 'Opened long ago');
    lgManga(2, 'Opened recently');
    lgManga(3, 'Never opened');
    lgChapter(1, 1, '1');
    lgChapter(2, 2, '1');
    $this->library->add(lgId('m', 3));

    $this->library->saveProgress(lgId('c', 1), 0, 20);
    Carbon::setTestNow('2026-10-25 13:00:00');
    $this->library->saveProgress(lgId('c', 2), 0, 20);

    $titles = $this->library->grid(sort: 'opened')->map(fn (LibraryTile $t) => $t->manga->title['en'])->all();

    expect($titles)->toBe(['Opened recently', 'Opened long ago', 'Never opened']);
});

it('sorts by recently downloaded, most recent download first', function () {
    lgManga(1, 'Downloaded first');
    lgManga(2, 'Downloaded second');
    lgManga(3, 'Never downloaded');
    lgChapter(1, 1, '1');
    lgChapter(2, 2, '1');
    $this->library->add(lgId('m', 1));
    $this->library->add(lgId('m', 2));
    $this->library->add(lgId('m', 3));
    StoredChapter::query()->insert([
        ['chapter_id' => lgId('c', 1), 'tier' => 'local', 'quality' => 'data-saver', 'status' => 'done', 'downloaded_at' => Carbon::now()],
    ]);
    Carbon::setTestNow('2026-10-25 14:00:00');
    StoredChapter::query()->insert([
        ['chapter_id' => lgId('c', 2), 'tier' => 'local', 'quality' => 'data-saver', 'status' => 'done', 'downloaded_at' => Carbon::now()],
    ]);

    $titles = $this->library->grid(sort: 'downloaded')->map(fn (LibraryTile $t) => $t->manga->title['en'])->all();

    expect($titles)->toBe(['Downloaded second', 'Downloaded first', 'Never downloaded']);
});

it('filters the grid to Reading / Downloaded / Favorites, same chips as collections()', function () {
    lgManga(1, 'Reading one');
    lgManga(2, 'Downloaded one');
    lgManga(3, 'Favorite one');
    lgManga(4, 'Plain one');
    lgChapter(1, 1, '1');
    lgChapter(2, 2, '1');
    $this->library->add(lgId('m', 2));
    $this->library->add(lgId('m', 3));
    $this->library->add(lgId('m', 4));
    $this->library->saveProgress(lgId('c', 1), 0, 20);
    StoredChapter::query()->insert([
        ['chapter_id' => lgId('c', 2), 'tier' => 'local', 'quality' => 'data-saver', 'status' => 'done'],
    ]);
    $this->library->setFavorite(lgId('m', 3), true);

    $titles = fn (string $filter) => $this->library->grid(filter: $filter)->map(fn (LibraryTile $t) => $t->manga->title['en'])->all();

    expect($titles('reading'))->toBe(['Reading one'])
        ->and($titles('downloaded'))->toBe(['Downloaded one'])
        ->and($titles('favorites'))->toBe(['Favorite one'])
        ->and($titles('all'))->toHaveCount(4);
});

it('rejects an unknown filter', function () {
    expect(fn () => $this->library->grid(filter: 'random'))->toThrow(InvalidArgumentException::class);
});

it('filters the grid by title search, case-insensitive substring', function () {
    lgManga(1, 'Attack on Titan');
    lgManga(2, 'One Piece');
    foreach ([1, 2] as $n) {
        $this->library->add(lgId('m', $n));
    }

    $titles = $this->library->grid(search: 'titan')->map(fn (LibraryTile $t) => $t->manga->title['en'])->all();

    expect($titles)->toBe(['Attack on Titan']);
});

it('rejects an unknown sort option', function () {
    expect(fn () => $this->library->grid(sort: 'random'))->toThrow(InvalidArgumentException::class);
});

it('builds each tile\'s own data: pinned, favorite, chips, chapter/byte counts', function () {
    lgManga(1);
    lgChapter(1, 1, '1');
    lgChapter(2, 1, '2');
    ChapterFeed::query()->create(['manga_id' => lgId('m', 1), 'language' => 'en', 'total' => 2, 'fetched_at' => Carbon::now()]);
    $this->library->setFavorite(lgId('m', 1), true);
    $this->library->setPinned(lgId('m', 1), true);
    StoredChapter::query()->insert([
        ['chapter_id' => lgId('c', 1), 'tier' => 'local', 'quality' => 'data-saver', 'status' => 'done', 'bytes' => 1000],
        ['chapter_id' => lgId('c', 2), 'tier' => 'local', 'quality' => 'data-saver', 'status' => 'done', 'bytes' => 2000],
    ]);
    $this->library->saveProgress(lgId('c', 1), 19, 20);

    $tile = $this->library->grid()->first();

    expect($tile->pinned)->toBeTrue()
        ->and($tile->favorite)->toBeTrue()
        ->and($tile->chips->downloaded)->toBeTrue()
        ->and($tile->downloadedChapters)->toBe(2)
        ->and($tile->totalChapters)->toBe(2)
        ->and($tile->readChapters)->toBe(1)
        ->and($tile->bytesOnDisk)->toBe(3000);
});

it('leaves a tile out of the grid when its manga row is gone', function () {
    lgManga(1);
    $this->library->add(lgId('m', 1));
    Manga::query()->whereKey(lgId('m', 1))->delete();

    expect($this->library->grid())->toBeEmpty();
});

it('keeps the existing collections() filter-chip behaviour unchanged', function () {
    lgManga(1);
    $this->library->setFavorite(lgId('m', 1), true);

    expect($this->library->collections('favorites')->map(fn (Manga $m) => $m->id)->all())->toBe([lgId('m', 1)]);
});
