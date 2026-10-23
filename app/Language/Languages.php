<?php

namespace App\Language;

/** Reading languages offered in Setup and Settings (spec §9 step 5), seeded locally, no API call. */
final class Languages
{
    /** MangaDex code => native name; Latin script A–Z first, then the other scripts. */
    public const NAMES = [
        'az' => 'Azərbaycanca',
        'id' => 'Bahasa Indonesia',
        'ms' => 'Bahasa Melayu',
        'ca' => 'Català',
        'cs' => 'Čeština',
        'da' => 'Dansk',
        'de' => 'Deutsch',
        'et' => 'Eesti',
        'en' => 'English',
        'es' => 'Español (España)',
        'es-la' => 'Español (Latinoamérica)',
        'eo' => 'Esperanto',
        'tl' => 'Filipino',
        'fr' => 'Français',
        'hr' => 'Hrvatski',
        'it' => 'Italiano',
        'la' => 'Latina',
        'lt' => 'Lietuvių',
        'hu' => 'Magyar',
        'nl' => 'Nederlands',
        'no' => 'Norsk',
        'pl' => 'Polski',
        'pt-br' => 'Português (Brasil)',
        'pt' => 'Português (Portugal)',
        'ro' => 'Română',
        'sk' => 'Slovenčina',
        'fi' => 'Suomi',
        'sv' => 'Svenska',
        'vi' => 'Tiếng Việt',
        'tr' => 'Türkçe',
        'el' => 'Ελληνικά',
        'bg' => 'Български',
        'kk' => 'Қазақ тілі',
        'ru' => 'Русский',
        'sr' => 'Српски',
        'uk' => 'Українська',
        'ka' => 'ქართული',
        'he' => 'עברית',
        'ar' => 'العربية',
        'fa' => 'فارسی',
        'ne' => 'नेपाली',
        'hi' => 'हिन्दी',
        'bn' => 'বাংলা',
        'ta' => 'தமிழ்',
        'th' => 'ไทย',
        'my' => 'မြန်မာ',
        'zh' => '中文 (简体)',
        'zh-hk' => '中文 (繁體)',
        'ja' => '日本語',
        'ko' => '한국어',
    ];

    /** Unknown codes show as the code itself. */
    public static function name(string $code): string
    {
        return self::NAMES[$code] ?? $code;
    }

    /** The code behind a picker label, or null for a label we never offered. */
    public static function codeFor(string $name): ?string
    {
        $code = array_search($name, self::NAMES, true);

        return $code === false ? null : $code;
    }
}
