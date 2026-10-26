<?php

namespace App\Language;

use InvalidArgumentException;

final readonly class LanguageChain
{
    private const SIBLINGS = [
        'es-la' => 'es',
        'es' => 'es-la',
        'pt-br' => 'pt',
        'pt' => 'pt-br',
    ];

    public string $picked;

    /** @var list<string> picked → sibling → en, no duplicates */
    public array $languages;

    public function __construct(string $picked)
    {
        $picked = strtolower(trim($picked));

        if ($picked === '') {
            throw new InvalidArgumentException('The picked language is empty.');
        }

        $this->picked = $picked;
        $this->languages = array_values(array_unique(array_filter([
            $picked,
            self::SIBLINGS[$picked] ?? null,
            'en',
        ])));
    }

    public static function fromIos(?string $iosTag): self
    {
        return new self(IosLanguageMap::toSourceCode($iosTag));
    }

    /** @param  list<string>  $available */
    public function covers(array $available): bool
    {
        return array_intersect($this->languages, $available) !== [];
    }

    /** @param  list<string>  $available */
    public function firstAvailable(array $available): ?string
    {
        foreach ($this->languages as $language) {
            if (in_array($language, $available, true)) {
                return $language;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $title
     * @param  list<array<string, string>>  $altTitles
     */
    public function title(array $title, array $altTitles): string
    {
        foreach ($this->languages as $language) {
            $text = $this->text($title, $language);

            foreach ($altTitles as $alt) {
                $text ??= $this->text($alt, $language);
            }

            if ($text !== null) {
                return $text;
            }
        }

        foreach ($title as $text) {
            if (trim((string) $text) !== '') {
                return $text;
            }
        }

        return '';
    }

    /** @param  array<string, string>  $description */
    public function description(array $description): ?string
    {
        foreach ($this->languages as $language) {
            if (($text = $this->text($description, $language)) !== null) {
                return $text;
            }
        }

        return null;
    }

    /** @param  list<string>  $available */
    public function badge(array $available): ?string
    {
        if ($this->picked === 'en') {
            return null;
        }

        $own = array_filter([$this->picked, self::SIBLINGS[$this->picked] ?? null]);

        return array_intersect($own, $available) === []
            ? null
            : strtoupper(explode('-', $this->picked)[0]);
    }

    /** @param  array<string, string>  $texts */
    private function text(array $texts, string $language): ?string
    {
        $text = $texts[$language] ?? null;

        return $text !== null && trim($text) !== '' ? $text : null;
    }
}
