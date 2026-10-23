<?php

namespace App\MangaDex\Mappers;

/** Localized text maps (`{"en": "…"}`). An empty one arrives as `[]` (sent as `{}` or `[]`). */
final class Text
{
    /** @return array<string, string> language => text, blank values dropped */
    public static function map(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $language => $text) {
            if (is_string($language) && is_string($text) && trim($text) !== '') {
                $map[$language] = $text;
            }
        }

        return $map;
    }
}
