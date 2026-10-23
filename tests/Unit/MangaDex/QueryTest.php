<?php

use App\MangaDex\Query;

it('builds MangaDex query strings', function (array $params, string $expected) {
    expect(Query::build($params))->toBe($expected);
})->with([
    'scalars' => [['limit' => 100, 'offset' => 0], 'limit=100&offset=0'],
    'lists as key[]' => [['ids' => ['a', 'b']], 'ids[]=a&ids[]=b'],
    'maps as key[sub]' => [['order' => ['followedCount' => 'desc']], 'order[followedCount]=desc'],
    'booleans as words' => [['hasAvailableChapters' => true, 'includeFuturePublishAt' => false], 'hasAvailableChapters=true&includeFuturePublishAt=false'],
    'nulls are skipped' => [['title' => null, 'limit' => 10], 'limit=10'],
    'values are encoded' => [['title' => 'Re:Zero & co'], 'title=Re%3AZero%20%26%20co'],
    'empty' => [[], ''],
]);
