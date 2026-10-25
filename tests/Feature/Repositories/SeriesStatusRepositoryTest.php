<?php

use App\Models\Chapter;
use App\Models\ChapterFeed;
use App\Models\Download;
use App\Models\Manga;
use App\Models\ReadingProgress;
use App\Models\StoredChapter;
use App\Repositories\LibraryRepository;
use App\Repositories\SeriesStatusRepository;
use Illuminate\Support\Carbon;

const SS_GROUP = 'ssssssss-0000-4000-8000-000000000000';

beforeEach(function () {
    Carbon::setTestNow('2026-10-25 12:00:00');
    $this->status = app(SeriesStatusRepository::class);
    $this->library = app(LibraryRepository::class);
});

function ssId(string $kind, int $n): string
{
    return $kind.sprintf('0000000-0000-4000-8000-%012d', $n);
}

function ssManga(int $n): Manga
{
    return Manga::query()->create([
        'id' => ssId('m', $n), 'title' => ['en' => "SS {$n}"], 'alt_titles' => [], 'description' => [],
        'content_rating' => 'safe', 'available_languages' => ['en'], 'tag_ids' => [], 'fetched_at' => Carbon::now(),
    ]);
}

function ssChapter(int $n, int $manga, string $number, array $extra = []): Chapter
{
    return Chapter::query()->create([
        'id' => ssId('c', $n), 'manga_id' => ssId('m', $manga), 'number' => $number, 'language' => 'en',
        'group_id' => SS_GROUP, 'group_name' => 'Group SS', 'pages' => 20, 'fetched_at' => Carbon::now(),
        ...$extra,
    ]);
}

it('gives a series with nothing stored and nothing queued no status facts to build a chip from', function () {
    ssManga(1);
    ssChapter(1, 1, '1');
    ssChapter(2, 1, '2');
    ChapterFeed::query()->create(['manga_id' => ssId('m', 1), 'language' => 'en', 'total' => 2, 'fetched_at' => Carbon::now()]);

    $facts = $this->status->factsFor(ssId('m', 1));

    expect($facts->releasedCount)->toBe(2)
        ->and($facts->downloadedCount)->toBe(0)
        ->and($facts->anyInProgress)->toBeFalse()
        ->and($facts->anyChapterOpened)->toBeFalse()
        // Vacuously true (nothing downloaded to fail the check); SeriesChips::from() still reads
        // this series as having no Finished chip, since it also requires downloadedCount > 0.
        ->and($facts->allDownloadedFinished)->toBeTrue()
        ->and($facts->catalogueTotal)->toBe(2);
});

it('counts every released chapter stored done (local/icloud) as downloaded, cache tier and partial excluded', function () {
    ssManga(1);
    ssChapter(1, 1, '1');
    ssChapter(2, 1, '2');
    ssChapter(3, 1, '3');
    ChapterFeed::query()->create(['manga_id' => ssId('m', 1), 'language' => 'en', 'total' => 3, 'fetched_at' => Carbon::now()]);
    StoredChapter::query()->insert([
        ['chapter_id' => ssId('c', 1), 'tier' => 'local', 'quality' => 'data-saver', 'status' => 'done'],
        ['chapter_id' => ssId('c', 2), 'tier' => 'icloud', 'quality' => 'data-saver', 'status' => 'done'],
        ['chapter_id' => ssId('c', 3), 'tier' => 'cache', 'quality' => 'data-saver', 'status' => 'done'],
    ]);

    expect($this->status->factsFor(ssId('m', 1))->downloadedCount)->toBe(2);
});

it('flags anyInProgress when a chapter is queued or downloading in the downloads table', function () {
    ssManga(1);
    $this->library->add(ssId('m', 1));
    $chapter = ssChapter(1, 1, '1');
    Download::query()->create([
        'chapter_id' => $chapter->id, 'manga_id' => ssId('m', 1), 'batch' => null, 'position' => 0,
        'priority' => 'interactive', 'started_at' => null, 'finished_at' => null, 'created_at' => Carbon::now(),
    ]);

    expect($this->status->factsFor(ssId('m', 1))->anyInProgress)->toBeTrue();
});

it('flags anyChapterOpened the moment reading progress exists for any chapter of the series', function () {
    ssManga(1);
    ssChapter(1, 1, '1');
    ssChapter(2, 1, '2');

    expect($this->status->factsFor(ssId('m', 1))->anyChapterOpened)->toBeFalse();

    $this->library->saveProgress(ssId('c', 2), 0, 20);

    expect($this->status->factsFor(ssId('m', 1))->anyChapterOpened)->toBeTrue();
});

it('judges allDownloadedFinished only against the downloaded subset', function () {
    ssManga(1);
    ssChapter(1, 1, '1');
    ssChapter(2, 1, '2');
    StoredChapter::query()->insert([
        ['chapter_id' => ssId('c', 1), 'tier' => 'local', 'quality' => 'data-saver', 'status' => 'done'],
    ]);
    $this->library->saveProgress(ssId('c', 1), 19, 20); // finished

    expect($this->status->factsFor(ssId('m', 1))->allDownloadedFinished)->toBeTrue();

    ReadingProgress::query()->whereKey(ssId('c', 1))->update(['finished_at' => null]);

    expect($this->status->factsFor(ssId('m', 1))->allDownloadedFinished)->toBeFalse();
});

it('uses the chapter feed total as the catalogue total for the series\' default language', function () {
    ssManga(1);
    ssChapter(1, 1, '1');
    ChapterFeed::query()->create(['manga_id' => ssId('m', 1), 'language' => 'en', 'total' => 5, 'fetched_at' => Carbon::now()]);

    expect($this->status->factsFor(ssId('m', 1))->catalogueTotal)->toBe(5);
});
