<?php

use App\Adapters\Http\Mappers\TagMapper;
use Tests\Support\MangaDexFixtures;

it('maps every recorded tag', function () {
    $rows = TagMapper::rows(MangaDexFixtures::body('tags'));

    expect($rows)->toHaveCount(77)
        ->and($rows[0])->toBe(['id' => '0234a31e-a729-4e28-9d6a-3f87c4966b9e', 'group' => 'format', 'name' => ['en' => 'Oneshot']])
        ->and(array_values(array_unique(array_column($rows, 'group'))))->toEqualCanonicalizing(['content', 'format', 'genre', 'theme']);
});

it('returns no rows for an empty response', function () {
    expect(TagMapper::rows(['result' => 'ok', 'data' => []]))->toBe([]);
});
