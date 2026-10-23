<?php

use App\Language\IosLanguageMap;
use App\Language\Languages;

it('offers every language the iPhone map can pick', function (string $iosTag) {
    expect(Languages::NAMES)->toHaveKey(IosLanguageMap::toMangaDex($iosTag));
})->with(['fr-CA', 'es-MX', 'es-ES', 'pt-BR', 'pt-PT', 'zh-Hans-CN', 'zh-Hant-HK', 'ja-JP', 'de-DE', 'tl-PH', 'xx-YY']);

it('offers every two-letter code the iPhone map accepts', function () {
    $twoLetter = (new ReflectionClassConstant(IosLanguageMap::class, 'TWO_LETTER'))->getValue();

    expect(array_diff($twoLetter, array_keys(Languages::NAMES)))->toBe([]);
});

it('has one label per language, so the picker label finds its code', function () {
    expect(array_unique(Languages::NAMES))->toBe(Languages::NAMES);

    foreach (Languages::NAMES as $code => $name) {
        expect(Languages::codeFor($name))->toBe($code)
            ->and(Languages::name($code))->toBe($name);
    }

    expect(Languages::codeFor('Klingon'))->toBeNull()
        ->and(Languages::name('xx'))->toBe('xx');
});

it('names the siblings apart', function () {
    expect(Languages::name('es-la'))->toBe('Español (Latinoamérica)')
        ->and(Languages::name('es'))->toBe('Español (España)')
        ->and(Languages::name('pt-br'))->toBe('Português (Brasil)')
        ->and(Languages::name('fr'))->toBe('Français');
});
