<?php

use App\Language\LanguageChain;

it('builds picked, sibling, English without duplicates', function (string $picked, array $expected) {
    expect((new LanguageChain($picked))->languages)->toBe($expected);
})->with([
    'Latin American Spanish' => ['es-la', ['es-la', 'es', 'en']],
    'Spain Spanish' => ['es', ['es', 'es-la', 'en']],
    'Brazilian Portuguese' => ['pt-br', ['pt-br', 'pt', 'en']],
    'Portugal Portuguese' => ['pt', ['pt', 'pt-br', 'en']],
    'French has no sibling' => ['fr', ['fr', 'en']],
    'English alone' => ['en', ['en']],
    'upper case is normalised' => ['ES-LA', ['es-la', 'es', 'en']],
]);

it('rejects an empty language', function () {
    new LanguageChain(' ');
})->throws(InvalidArgumentException::class);

it('starts from the iPhone language', function () {
    expect(LanguageChain::fromIos('fr-CA')->languages)->toBe(['fr', 'en'])
        ->and(LanguageChain::fromIos('es-MX')->languages)->toBe(['es-la', 'es', 'en'])
        ->and(LanguageChain::fromIos(null)->languages)->toBe(['en']);
});

it('tells whether a series has any chain language', function () {
    $chain = new LanguageChain('es-la');

    expect($chain->covers(['fr', 'es']))->toBeTrue()
        ->and($chain->covers(['en']))->toBeTrue()
        ->and($chain->covers(['fr', 'ja']))->toBeFalse()
        ->and($chain->covers([]))->toBeFalse();
});

it('opens the chapter list on the first chain language the series has', function () {
    $chain = new LanguageChain('es-la');

    expect($chain->firstAvailable(['en', 'es']))->toBe('es')
        ->and($chain->firstAvailable(['en', 'es-la', 'es']))->toBe('es-la')
        ->and($chain->firstAvailable(['fr']))->toBeNull();
});

it('picks the title by chain, then alt titles, then the main title', function () {
    $chain = new LanguageChain('es-la');
    $title = ['ja-ro' => 'Wandaa', 'en' => 'Return to Wonder'];

    expect($chain->title($title, [['es' => 'Regreso (ES)'], ['es-la' => 'Regreso']]))->toBe('Regreso')
        ->and($chain->title($title, [['es' => 'Regreso (ES)']]))->toBe('Regreso (ES)')
        ->and($chain->title($title, [['ko' => '원더']]))->toBe('Return to Wonder')
        ->and($chain->title(['ja-ro' => 'Wandaa'], []))->toBe('Wandaa')
        ->and($chain->title([], []))->toBe('');
});

it('skips blank texts when picking', function () {
    $chain = new LanguageChain('es-la');

    expect($chain->title(['es-la' => '  ', 'en' => 'Return'], []))->toBe('Return')
        ->and($chain->description(['es-la' => '', 'en' => 'Text']))->toBe('Text');
});

it('falls back to no description outside the chain', function () {
    $chain = new LanguageChain('es-la');

    expect($chain->description(['es' => 'Texto', 'en' => 'Text']))->toBe('Texto')
        ->and($chain->description(['ko' => '설명']))->toBeNull()
        ->and($chain->description([]))->toBeNull();
});

it('badges a series that has the picked language or its sibling', function () {
    expect((new LanguageChain('es-la'))->badge(['en', 'es']))->toBe('ES')
        ->and((new LanguageChain('pt-br'))->badge(['pt-br']))->toBe('PT')
        ->and((new LanguageChain('fr'))->badge(['fr']))->toBe('FR')
        ->and((new LanguageChain('es-la'))->badge(['en']))->toBeNull()
        ->and((new LanguageChain('en'))->badge(['en']))->toBeNull();
});
