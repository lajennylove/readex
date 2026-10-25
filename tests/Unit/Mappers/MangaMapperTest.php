<?php

use App\Adapters\Http\Mappers\MangaMapper;
use Tests\Support\SourceFixtures;

it('maps a search result with its cover file name', function () {
    $rows = MangaMapper::rows(SourceFixtures::body('search-one-punch'));
    $opm = collect($rows)->firstWhere('id', 'd8a959f7-648e-4c8d-8f23-f1f3f8e129f3');

    expect($rows)->toHaveCount(18)
        ->and($opm['title'])->toBe(['ja-ro' => 'One Punch-Man'])
        ->and($opm['alt_titles'])->toHaveCount(28)
        ->and($opm['alt_titles'][0])->toBeArray()
        ->and($opm['status'])->toBe('ongoing')
        ->and($opm['year'])->toBe(2012)
        ->and($opm['content_rating'])->toBe('suggestive')
        ->and($opm['original_language'])->toBe('ja')
        ->and($opm['available_languages'])->toContain('es', 'es-la')
        ->and($opm['tag_ids'])->toHaveCount(9)
        ->and($opm['cover_file'])->toBe('d22cfde3-268a-48a3-b5ed-73772834764d.jpg')
        ->and(array_keys($opm))->toBe(['id', 'title', 'alt_titles', 'description', 'status', 'year', 'content_rating', 'available_languages', 'original_language', 'tag_ids', 'cover_file']);
});

it('keeps the API order', function () {
    $ids = array_column(MangaMapper::rows(SourceFixtures::body('search-one-punch')), 'id');

    expect(array_slice($ids, 0, 3))->toBe([
        'a1c9cef0-540e-4f71-8891-ff7896cdde01',
        'b7d069cb-4ab9-4c21-a20b-38f7c269be4e',
        'd8a959f7-648e-4c8d-8f23-f1f3f8e129f3',
    ]);
});

it('turns an empty description object into an empty map', function () {
    // Recorded as `"description": {}`; PHP decodes it to [].
    $row = collect(MangaMapper::rows(SourceFixtures::body('search-one-punch')))
        ->firstWhere('id', 'a1c9cef0-540e-4f71-8891-ff7896cdde01');

    expect($row['description'])->toBe([])
        ->and($row['year'])->toBeNull();
});

it('maps a detail response with empty lists', function () {
    $row = MangaMapper::row(SourceFixtures::body('manga-nova')['data']);

    expect($row['title'])->toBe(['zh-ro' => 'Nova'])
        ->and($row['alt_titles'])->toBe([])
        ->and($row['available_languages'])->toBe([])
        ->and(array_keys($row['description']))->toBe(['es-la'])
        ->and($row['cover_file'])->toBe('e0c01cf2-e693-4133-a86c-f76cab24be9c.jpg')
        ->and($row['tag_ids'])->toHaveCount(5);
});

it('drops blank texts, null languages and duplicates', function () {
    $row = MangaMapper::row([
        'id' => 'm1',
        'attributes' => [
            'title' => ['en' => 'Title', 'es-la' => '  '],
            'altTitles' => [['es' => ''], ['ko' => '제목']],
            'description' => ['en' => ''],
            'contentRating' => 'safe',
            'availableTranslatedLanguages' => ['en', null, 'en', 'es-la'],
            'tags' => [],
        ],
        'relationships' => [],
    ]);

    expect($row['title'])->toBe(['en' => 'Title'])
        ->and($row['alt_titles'])->toBe([['ko' => '제목']])
        ->and($row['description'])->toBe([])
        ->and($row['available_languages'])->toBe(['en', 'es-la'])
        ->and($row['status'])->toBeNull()
        ->and($row['original_language'])->toBeNull();
});

it('maps author and artist names from a detail response', function () {
    $rtw = MangaMapper::row(SourceFixtures::body('manga-rtw')['data']);
    $nova = MangaMapper::row(SourceFixtures::body('manga-nova')['data']);

    expect($rtw['authors'])->toBe(['Er Mu (二目)'])
        ->and($rtw['artists'])->toBe(['Yuewen Manhua (阅文漫画)', 'Dr. Woodman'])
        ->and($nova['authors'])->toBe(['Clevercool'])
        ->and($nova['artists'])->toBe(['Clevercool']);
});

it('leaves authors and artists out when they were not included, so a list call keeps saved names', function () {
    $manga = SourceFixtures::body('manga-rtw')['data'];
    $manga['relationships'] = array_map(
        fn (array $relation) => array_diff_key($relation, ['attributes' => true]),
        $manga['relationships'],
    );

    expect(MangaMapper::row($manga))->not->toHaveKeys(['authors', 'artists']);
});

it('drops repeated and blank names', function () {
    $manga = SourceFixtures::body('manga-nova')['data'];
    $manga['relationships'] = [
        ['id' => 'p1', 'type' => 'author', 'attributes' => ['name' => 'Ann']],
        ['id' => 'p2', 'type' => 'author', 'attributes' => ['name' => ' ']],
        ['id' => 'p1', 'type' => 'author', 'attributes' => ['name' => 'Ann']],
        ['id' => 'p3', 'type' => 'artist', 'attributes' => ['name' => 'Bo']],
    ];

    $row = MangaMapper::row($manga);

    expect($row['authors'])->toBe(['Ann'])
        ->and($row['artists'])->toBe(['Bo']);
});

it('has no cover file when cover_art was not included', function () {
    $manga = SourceFixtures::body('manga-nova')['data'];
    $manga['relationships'] = [['id' => 'c1', 'type' => 'cover_art']];

    expect(MangaMapper::row($manga)['cover_file'])->toBeNull();
});
