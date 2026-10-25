<?php

namespace App\Adapters\Http\Mappers;

/** Manga entities → `manga` rows (without `fetched_at`, the repository sets it). */
final class MangaMapper
{
    public static function row(array $manga): array
    {
        $a = $manga['attributes'];

        return [
            'id' => $manga['id'],
            'title' => Text::map($a['title'] ?? []),
            'alt_titles' => array_values(array_filter(array_map(Text::map(...), $a['altTitles'] ?? []))),
            'description' => Text::map($a['description'] ?? []),
            'status' => $a['status'] ?? null,
            'year' => isset($a['year']) ? (int) $a['year'] : null,
            'content_rating' => $a['contentRating'],
            // The source sometimes lists null or a language twice.
            'available_languages' => array_values(array_unique(array_filter($a['availableTranslatedLanguages'] ?? [], 'is_string'))),
            'original_language' => $a['originalLanguage'] ?? null,
            'tag_ids' => array_column($a['tags'] ?? [], 'id'),
            'cover_file' => self::coverFile($manga),
            ...self::people($manga),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function rows(array $response): array
    {
        return array_map(self::row(...), $response['data'] ?? []);
    }

    /** Only present when the call sent `includes[]=cover_art` (choice 6). */
    private static function coverFile(array $manga): ?string
    {
        foreach ($manga['relationships'] ?? [] as $relation) {
            if ($relation['type'] === 'cover_art' && isset($relation['attributes']['fileName'])) {
                return $relation['attributes']['fileName'];
            }
        }

        return null;
    }

    /**
     * `authors` / `artists` only when the call sent `includes[]=author&includes[]=artist` (detail call),
     * so list calls, which don't, leave the saved names alone.
     */
    private static function people(array $manga): array
    {
        $names = ['author' => [], 'artist' => []];
        $included = false;

        foreach ($manga['relationships'] ?? [] as $relation) {
            if (isset($names[$relation['type']], $relation['attributes'])) {
                $included = true;
                $names[$relation['type']][] = trim($relation['attributes']['name'] ?? '');
            }
        }

        if (! $included) {
            return [];
        }

        $clean = fn (array $list) => array_values(array_unique(array_filter($list, fn (string $name) => $name !== '')));

        return ['authors' => $clean($names['author']), 'artists' => $clean($names['artist'])];
    }
}
