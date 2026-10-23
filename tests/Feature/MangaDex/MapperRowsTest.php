<?php

use App\MangaDex\Mappers\ChapterMapper;
use App\MangaDex\Mappers\MangaMapper;
use App\MangaDex\Mappers\TagMapper;
use App\Models\Chapter;
use App\Models\Manga;
use App\Models\Tag;
use Illuminate\Support\Carbon;
use Tests\Support\MangaDexFixtures;

it('saves mapped rows through the models unchanged', function () {
    $now = Carbon::now();
    $manga = MangaMapper::rows(MangaDexFixtures::body('search-one-punch'))[2];
    $chapter = ChapterMapper::rows(MangaDexFixtures::body('feed-one-punch-es-la'))[0];
    $tag = TagMapper::rows(MangaDexFixtures::body('tags'))[0];

    Manga::create([...$manga, 'fetched_at' => $now]);
    Chapter::create([...$chapter, 'fetched_at' => $now]);
    Tag::create([...$tag, 'fetched_at' => $now]);

    $saved = Manga::find($manga['id']);

    expect($saved->title)->toBe($manga['title'])
        ->and($saved->alt_titles)->toBe($manga['alt_titles'])
        ->and($saved->available_languages)->toBe($manga['available_languages'])
        ->and($saved->tag_ids)->toBe($manga['tag_ids'])
        ->and($saved->year)->toBe(2012)
        ->and(Chapter::find($chapter['id'])->readable_at->equalTo($chapter['readable_at']))->toBeTrue()
        ->and(Chapter::find($chapter['id'])->number)->toBe('20')
        ->and(Tag::find($tag['id'])->name)->toBe(['en' => 'Oneshot']);
});
