<?php

use App\MangaDex\Mappers\ChapterMapper;
use Carbon\CarbonImmutable;
use Tests\Support\MangaDexFixtures;

const OPM_ID = 'd8a959f7-648e-4c8d-8f23-f1f3f8e129f3';

function opmChapter(string $id): array
{
    return collect(ChapterMapper::rows(MangaDexFixtures::body('feed-one-punch-es-la')))->firstWhere('id', $id);
}

it('maps a feed chapter with its group', function () {
    expect(opmChapter('1696c95a-bfc9-400c-912f-bae46e6029e0'))->toEqual([
        'id' => '1696c95a-bfc9-400c-912f-bae46e6029e0',
        'manga_id' => OPM_ID,
        'volume' => '3',
        'number' => '20',
        'title' => 'Rumor',
        'language' => 'es-la',
        'group_id' => '3fbce7cb-7bc8-4864-af84-e5ad287fe0f9',
        'group_name' => 'Ichi no Fansub',
        'pages' => 45,
        'external_url' => null,
        'readable_at' => CarbonImmutable::parse('2018-06-24 16:36:11', 'UTC'),
    ]);
});

it('maps the whole recorded feed in API order', function () {
    $rows = ChapterMapper::rows(MangaDexFixtures::body('feed-one-punch-es-la'));

    expect($rows)->toHaveCount(182)
        ->and(array_unique(array_column($rows, 'manga_id')))->toBe([OPM_ID])
        ->and($rows[0]['id'])->toBe('1696c95a-bfc9-400c-912f-bae46e6029e0')
        ->and(collect($rows)->whereNull('volume'))->toHaveCount(132)
        ->and(collect($rows)->whereNull('group_id'))->toHaveCount(3);
});

it('keeps decimal numbers as strings and blank titles as null', function () {
    $row = opmChapter('0b3e83ff-8cf1-49c5-91ae-a46ec59c3f32');

    expect($row['number'])->toBe('126.2')
        ->and($row['volume'])->toBe('26')
        ->and($row['title'])->toBeNull();
});

it('has no group for a chapter uploaded without one', function () {
    $row = opmChapter('cf7ab4e1-e62d-4f85-a18e-ef27e800c592');

    expect($row['group_id'])->toBeNull()
        ->and($row['group_name'])->toBeNull();
});

it('keeps the first group of a joint release', function () {
    expect(opmChapter('c3240e20-1e32-4098-a5e0-6edca43f615b')['group_name'])->toBe('Ouroboros Scans');
});

it('maps external chapters with zero pages', function () {
    $rows = collect(ChapterMapper::rows(MangaDexFixtures::body('feed-chainsaw-man-en')));
    $first = $rows->firstWhere('id', '6f2b5712-2461-48fd-a519-c2c9cb93f0b1');

    expect($rows)->toHaveCount(108)
        ->and($rows->whereNotNull('external_url'))->toHaveCount(10)
        ->and($first['external_url'])->toBe('https://viz.com/shonenjump/-/chapter/17489')
        ->and($first['pages'])->toBe(0)
        ->and($first['group_name'])->toBe('Viz Manga (Anglosphere Only)');
});

it('maps a oneshot with null number and volume', function () {
    $row = ChapterMapper::rows(MangaDexFixtures::body('feed-oneshot-en'))[0];

    expect($row['number'])->toBeNull()
        ->and($row['volume'])->toBeNull()
        ->and($row['title'])->toBeNull()
        ->and($row['manga_id'])->toBe('69141247-f88f-4149-a994-31612cb3eb74')
        ->and($row['pages'])->toBe(20);
});

it('leaves out unavailable chapters', function () {
    $response = MangaDexFixtures::body('feed-oneshot-en');
    $response['data'][] = array_replace_recursive($response['data'][0], ['id' => 'gone', 'attributes' => ['isUnavailable' => true]]);

    expect(array_column(ChapterMapper::rows($response), 'id'))->toBe(['4e01bda4-0a04-4cdd-b3a9-edcafa7bbfd4']);
});
