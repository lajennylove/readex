<?php

namespace App\Language;

final class IosLanguageMap
{
    /** Two-letter MangaDex codes accepted as-is (from MangaDex's language list). */
    private const TWO_LETTER = [
        'ar', 'az', 'bg', 'bn', 'ca', 'cs', 'da', 'de', 'el', 'en', 'eo', 'et', 'fa', 'fi', 'fr', 'he',
        'hi', 'hr', 'hu', 'id', 'it', 'ja', 'ka', 'kk', 'ko', 'la', 'lt', 'ms', 'my', 'ne', 'nl', 'no',
        'pl', 'ro', 'ru', 'sk', 'sr', 'sv', 'ta', 'th', 'tl', 'tr', 'uk', 'vi',
    ];

    public static function toMangaDex(?string $iosTag): string
    {
        if ($iosTag === null || $iosTag === '') {
            return 'en';
        }

        $parts = explode('-', str_replace('_', '-', $iosTag));
        $lang = strtolower($parts[0]);
        $rest = array_map('strtolower', array_slice($parts, 1));

        return match (true) {
            $lang === 'es' => in_array('es', $rest, true) ? 'es' : 'es-la',
            $lang === 'pt' => in_array('br', $rest, true) ? 'pt-br' : 'pt',
            $lang === 'zh' => in_array('hant', $rest, true) ? 'zh-hk' : 'zh',
            in_array($lang, self::TWO_LETTER, true) => $lang,
            default => 'en',
        };
    }
}
