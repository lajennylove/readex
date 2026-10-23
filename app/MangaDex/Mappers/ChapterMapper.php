<?php

namespace App\MangaDex\Mappers;

use Carbon\CarbonImmutable;

/** Chapter entities (`/manga/{id}/feed`, `/chapter`) → `chapters` rows (without `fetched_at`). */
final class ChapterMapper
{
    public static function row(array $chapter): array
    {
        $a = $chapter['attributes'];
        $group = self::related($chapter, 'scanlation_group');

        return [
            'id' => $chapter['id'],
            'manga_id' => self::related($chapter, 'manga')['id'] ?? null,
            'volume' => self::blankToNull($a['volume'] ?? null),
            'number' => self::blankToNull($a['chapter'] ?? null),
            'title' => self::blankToNull($a['title'] ?? null),
            'language' => $a['translatedLanguage'],
            'group_id' => $group['id'] ?? null,
            // Only present when the call sent `includes[]=scanlation_group`.
            'group_name' => $group['attributes']['name'] ?? null,
            'pages' => (int) ($a['pages'] ?? 0),
            'external_url' => self::blankToNull($a['externalUrl'] ?? null),
            'readable_at' => isset($a['readableAt']) ? CarbonImmutable::parse($a['readableAt'])->utc() : null,
        ];
    }

    /** `isUnavailable` chapters are left out (spec §11.3: hidden from the feed). */
    public static function rows(array $response): array
    {
        $rows = [];

        foreach ($response['data'] ?? [] as $chapter) {
            if (! ($chapter['attributes']['isUnavailable'] ?? false)) {
                $rows[] = self::row($chapter);
            }
        }

        return $rows;
    }

    /** First relationship of a type. A chapter by several groups keeps the first one. */
    private static function related(array $chapter, string $type): ?array
    {
        foreach ($chapter['relationships'] ?? [] as $relation) {
            if ($relation['type'] === $type) {
                return $relation;
            }
        }

        return null;
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }
}
