<?php

use App\Adapters\Http\Client;
use App\Adapters\Http\Mappers\ChapterMapper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use Tests\Support\MangaDexFixtures;

beforeEach(function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(1_792_756_800));
    Sleep::fake(syncWithCarbon: true);
});

it('serves a fixture whatever the query order', function () {
    MangaDexFixtures::fake('feed-oneshot-en');

    $data = app(Client::class)->get('/manga/69141247-f88f-4149-a994-31612cb3eb74/feed', [
        'order' => ['volume' => 'asc', 'chapter' => 'asc'],
        'includes' => ['scanlation_group'],
        'contentRating' => ['pornographic', 'erotica', 'suggestive', 'safe'],
        'offset' => 0,
        'limit' => 500,
        'translatedLanguage' => ['en'],
    ]);

    expect(ChapterMapper::rows($data)[0]['id'])->toBe('4e01bda4-0a04-4cdd-b3a9-edcafa7bbfd4');
});

it('treats another sort priority as another request', function () {
    MangaDexFixtures::fake('feed-oneshot-en');

    app(Client::class)->get('/manga/69141247-f88f-4149-a994-31612cb3eb74/feed', [
        'translatedLanguage' => ['en'],
        'limit' => 500,
        'offset' => 0,
        'contentRating' => ['safe', 'suggestive', 'erotica', 'pornographic'],
        'includes' => ['scanlation_group'],
        'order' => ['chapter' => 'asc', 'volume' => 'asc'],
    ]);
})->throws(RuntimeException::class, 'No MangaDex fixture');

it('fails loudly for an unrecorded request', function () {
    MangaDexFixtures::fake('tags');

    app(Client::class)->get('/manga/tag', ['limit' => 1]);
})->throws(RuntimeException::class, 'No MangaDex fixture for GET https://api.mangadex.org/manga/tag?limit=1');

it('keeps empty objects as recorded', function () {
    expect(file_get_contents(MangaDexFixtures::path('search-one-punch')))->toContain('"description": {}')
        ->and(MangaDexFixtures::body('search-one-punch')['data'][0]['attributes']['description'])->toBe([]);
});

it('has a recording for every listed fixture', function () {
    $names = array_keys(require base_path('tests/Fixtures/mangadex/fixtures.php'));

    foreach ($names as $name) {
        expect(MangaDexFixtures::path($name))->toBeFile();
    }
});
