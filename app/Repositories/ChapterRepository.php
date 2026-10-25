<?php

namespace App\Repositories;

use App\Adapters\Http\Client;
use App\Adapters\Http\Mappers\ChapterMapper;
use App\Adapters\Http\RateLimit\Priority;
use App\Models\Chapter;
use App\Models\ChapterFeed;
use App\Models\Collection as LibraryEntry;
use App\Models\Manga;
use App\Models\StoredChapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ChapterRepository
{
    /** The source rejects offset + limit above this. */
    private const MAX_RESULTS = 10_000;

    public function __construct(
        private readonly Client $client,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * Chapters of one language in reading order. Rows with `fetched_at = null` (gone from the
     * latest feed but stored, or kept by DataReset) are left out; the Library shows them.
     */
    public function get(string $mangaId, string $language): Result
    {
        $feed = ChapterFeed::query()->where('manga_id', $mangaId)->where('language', $language)->first();

        $chapters = Chapter::query()
            ->where('manga_id', $mangaId)
            ->where('language', $language)
            ->whereNotNull('fetched_at')
            ->get()
            ->sort(self::compare(...))
            ->values();

        return Freshness::result($chapters, $feed?->fetched_at, $this->kind($mangaId));
    }

    /**
     * Fetches every page of the feed (500 per call) and replaces the saved rows in one
     * transaction, so a failed page leaves SQLite untouched (Review Focus 4).
     */
    public function refresh(string $mangaId, string $language, Priority $priority = Priority::Interactive): void
    {
        $size = config('readex.page_sizes.feed');
        $offset = 0;
        $rows = [];

        do {
            $limit = min($size, self::MAX_RESULTS - $offset);
            $response = $this->client->get("/manga/{$mangaId}/feed", [
                'translatedLanguage' => [$language],
                'limit' => $limit,
                'offset' => $offset,
                // The series was already filtered by rating; Library series keep their feed when the setting is lowered.
                'contentRating' => config('readex.content_ratings'),
                'includes' => ['scanlation_group'],
                'order' => ['volume' => 'asc', 'chapter' => 'asc'],
            ], $priority);

            array_push($rows, ...ChapterMapper::rows($response));
            $total = (int) ($response['total'] ?? 0);
            $offset += $limit;
        } while (($response['data'] ?? []) !== [] && $offset < min($total, self::MAX_RESULTS));

        DB::transaction(function () use ($mangaId, $language, $rows, $total) {
            $now = Carbon::now();
            $ids = array_column($rows, 'id');
            $gone = Chapter::query()->where('manga_id', $mangaId)->where('language', $language)->whereNotIn('id', $ids);

            // Spec §11.3: a removed chapter that is already stored stays for the Library.
            $stored = StoredChapter::query()->whereIn('chapter_id', (clone $gone)->select('id'))->pluck('chapter_id');
            (clone $gone)->whereIn('id', $stored)->update(['fetched_at' => null]);
            $gone->whereNotIn('id', $stored)->delete();

            foreach (array_chunk($rows, 100) as $chunk) {
                Chapter::query()->upsert(
                    array_map(fn (array $row) => [...$row, 'manga_id' => $mangaId, 'fetched_at' => $now], $chunk),
                    ['id'],
                );
            }

            ChapterFeed::query()->updateOrInsert(
                ['manga_id' => $mangaId, 'language' => $language],
                ['total' => $total, 'fetched_at' => $now],
            );
        });
    }

    /**
     * Volume sections (D14): volumes in natural order, "No volume yet" (`volume = null`) last.
     * A number goes into the volume any of its uploads has, so it never shows up twice.
     *
     * @return list<array{volume: ?string, chapters: Collection<int, Chapter>}>
     */
    public function sections(string $mangaId, string $language): array
    {
        return self::group($this->get($mangaId, $language)->data);
    }

    /**
     * The grouping behind `sections()`, also used by the Library (Task 9).
     *
     * @param  Collection<int, Chapter>  $chapters
     * @return list<array{volume: ?string, chapters: Collection<int, Chapter>}>
     */
    public static function group(Collection $chapters): array
    {
        $volumeOf = $chapters
            ->filter(fn (Chapter $c) => $c->number !== null && $c->volume !== null)
            ->groupBy('number')
            ->map(fn (Collection $uploads) => $uploads->pluck('volume')->sort(strnatcmp(...))->first());

        $sections = $chapters
            ->groupBy(fn (Chapter $c) => $c->volume ?? ($c->number === null ? null : $volumeOf->get($c->number)) ?? '')
            ->map(fn (Collection $group, string $volume) => [
                'volume' => $volume === '' ? null : $volume,
                'chapters' => $group->sort(self::compare(...))->values(),
            ])
            ->values()
            ->all();

        usort($sections, fn (array $a, array $b) => match (true) {
            $a['volume'] === $b['volume'] => 0,
            $a['volume'] === null => 1,
            $b['volume'] === null => -1,
            default => strnatcmp($a['volume'], $b['volume']),
        });

        return $sections;
    }

    /**
     * Languages for the chapter-list switch: the reading chain first (in chain order), then the
     * rest of the series' languages alphabetically.
     *
     * @return list<string>
     */
    public function languages(string $mangaId): array
    {
        $available = $this->readableLanguages($mangaId);
        $chain = array_values(array_intersect($this->settings->chain()->languages, $available));
        $rest = array_values(array_diff($available, $chain));
        sort($rest);

        return [...$chain, ...$rest];
    }

    /** The language the chapter list opens in: first chain language the series has. */
    public function defaultLanguage(string $mangaId): ?string
    {
        return $this->settings->chain()->firstAvailable($this->readableLanguages($mangaId));
    }

    /**
     * The series' languages minus those whose fresh feed has no readable chapter: the source keeps
     * a language in `availableTranslatedLanguages` after all its uploads become unavailable. Once
     * that feed goes stale the language is offered again, so new uploads still show up.
     *
     * @return list<string>
     */
    private function readableLanguages(string $mangaId): array
    {
        $available = Manga::query()->find($mangaId)?->available_languages ?? [];
        $kind = $this->kind($mangaId);
        $withChapters = Chapter::query()->where('manga_id', $mangaId)->whereNotNull('fetched_at')->distinct()->pluck('language');
        $empty = ChapterFeed::query()->where('manga_id', $mangaId)->get()
            ->reject(fn (ChapterFeed $feed) => Freshness::isStale($feed->fetched_at, $kind) || $withChapters->contains($feed->language))
            ->pluck('language')
            ->all();

        return array_values(array_diff($available, $empty));
    }

    /**
     * Reading order: natural number order (`82` < `82.1` < `82.10` < `83`), oneshots (no number)
     * after numbered chapters, then group name and release time for duplicate uploads.
     */
    public static function compare(Chapter $a, Chapter $b): int
    {
        return match (true) {
            $a->number === null && $b->number !== null => 1,
            $a->number !== null && $b->number === null => -1,
            default => strnatcmp((string) $a->number, (string) $b->number),
        } ?: strcmp((string) $a->group_name, (string) $b->group_name)
            ?: ($a->readable_at?->getTimestamp() ?? 0) <=> ($b->readable_at?->getTimestamp() ?? 0)
            ?: strcmp($a->id, $b->id);
    }

    private function kind(string $mangaId): string
    {
        return LibraryEntry::query()->whereKey($mangaId)->exists() ? 'feed.library' : 'feed';
    }
}
