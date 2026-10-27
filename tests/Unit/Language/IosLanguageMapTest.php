<?php

use App\Language\IosLanguageMap;

it('maps iPhone language tags to the source codes', function (?string $tag, string $expected) {
    expect(IosLanguageMap::toSourceCode($tag))->toBe($expected);
})->with([
    'Spain' => ['es-ES', 'es'],
    'Mexico' => ['es-MX', 'es-la'],
    'Latin America region' => ['es-419', 'es-la'],
    'Brazil' => ['pt-BR', 'pt-br'],
    'Portugal' => ['pt-PT', 'pt'],
    'Simplified Chinese' => ['zh-Hans-CN', 'zh'],
    'Traditional Chinese' => ['zh-Hant-TW', 'zh-hk'],
    'Canadian French (user)' => ['fr-CA', 'fr'],
    'Japanese' => ['ja-JP', 'ja'],
    'unknown language' => ['xx-YY', 'en'],
    'nothing' => [null, 'en'],
]);
