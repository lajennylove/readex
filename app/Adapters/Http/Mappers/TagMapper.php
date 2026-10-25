<?php

namespace App\Adapters\Http\Mappers;

/** `/manga/tag` entries (same shape as `manga.attributes.tags`) → `tags` rows. */
final class TagMapper
{
    /** @return array{id: string, group: string, name: array<string, string>} */
    public static function row(array $tag): array
    {
        return [
            'id' => $tag['id'],
            'group' => $tag['attributes']['group'],
            'name' => Text::map($tag['attributes']['name'] ?? []),
        ];
    }

    /** @return list<array{id: string, group: string, name: array<string, string>}> */
    public static function rows(array $response): array
    {
        return array_map(self::row(...), $response['data'] ?? []);
    }
}
