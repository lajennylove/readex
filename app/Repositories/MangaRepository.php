<?php

namespace App\Repositories;

use App\Language\LanguageChain;
use App\MangaDex\Client;
use App\MangaDex\Mappers\MangaMapper;
use App\MangaDex\RateLimit\Priority;
use App\Models\Manga;
use App\Models\MangaListEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class MangaRepository
{
    /** MangaDex rejects offset + limit above this. */
    private const MAX_RESULTS = 10_000;

    public function __construct(
        private readonly Client $client,
        private readonly SettingsRepository $settings,
    ) {}

    /** Choice 4: query, tags and content ratings, never the language (ordering happens in get()). */
    public function listKey(string $query, array $tagIds = []): string
    {
        $tagIds = array_values(array_unique($tagIds));
        sort($tagIds);

        return 'search:'.substr(sha1(json_encode([
            mb_strtolower(preg_replace('/\s+/u', ' ', trim($query))),
            $tagIds,
            $this->settings->contentRatings(),
        ])), 0, 16);
    }

    /** Search by title and/or tags (empty title = browse by tag), in reading-language order. */
    public function search(string $query = '', array $tagIds = []): Result
    {
        return $this->readList($this->listKey($query, $tagIds), 'list');
    }

    /**
     * Fetches page 1 and replaces the saved list. Nothing is deleted when the call fails.
     *
     * @return bool more results are available
     */
    public function refreshSearch(string $query = '', array $tagIds = [], Priority $priority = Priority::Interactive): bool
    {
        $response = $this->client->get('/manga', $this->searchQuery($query, $tagIds, 0, config('readex.page_sizes.list')), $priority);
        $key = $this->listKey($query, $tagIds);

        DB::transaction(function () use ($response, $key) {
            MangaListEntry::query()->where('list_key', $key)->delete();
            $this->appendBatch($key, 1, 0, $this->storeManga($response));
        });

        return $this->hasMore($response, 0);
    }

    /**
     * Appends the next page as a new batch; tiles already shown keep their place (Review Focus 2).
     *
     * @return bool more results are available after this page
     */
    public function loadMore(string $query = '', array $tagIds = [], Priority $priority = Priority::Interactive): bool
    {
        $key = $this->listKey($query, $tagIds);
        $last = MangaListEntry::query()->where('list_key', $key)->selectRaw('max(batch) as batch, max(position) as position')->first();

        if ($last?->batch === null) {
            return $this->refreshSearch($query, $tagIds, $priority);
        }

        $offset = (int) $last->position + 1;
        $limit = min(config('readex.page_sizes.list'), self::MAX_RESULTS - $offset);

        if ($limit <= 0) {
            return false;
        }

        $response = $this->client->get('/manga', $this->searchQuery($query, $tagIds, $offset, $limit), $priority);

        DB::transaction(function () use ($response, $key, $last, $offset) {
            $this->appendBatch($key, (int) $last->batch + 1, $offset, $this->storeManga($response));
        });

        return $this->hasMore($response, $offset);
    }

    /**
     * A saved list in reading-language order: each batch on its own, series with chapters in the
     * picked language (or its sibling) first, then English, then the rest; API order inside each group.
     */
    public function readList(string $key, string $kind): Result
    {
        $entries = MangaListEntry::query()->where('list_key', $key)->orderBy('position')->get();
        $manga = Manga::query()->findMany($entries->pluck('manga_id'))->keyBy('id');
        $chain = $this->settings->chain();

        $ordered = $entries
            ->filter(fn (MangaListEntry $e) => $manga->has($e->manga_id))
            ->groupBy('batch')
            ->sortKeys()
            ->flatMap(fn (Collection $batch) => $batch->sort(fn (MangaListEntry $a, MangaListEntry $b) => [$this->rank($chain, $manga[$a->manga_id]), $a->position] <=> [$this->rank($chain, $manga[$b->manga_id]), $b->position]))
            ->map(fn (MangaListEntry $e) => $manga[$e->manga_id])
            ->values();

        $fetchedAt = $entries->contains(fn (MangaListEntry $e) => $e->fetched_at === null) ? null : $entries->min('fetched_at');

        return Freshness::result($ordered, $fetchedAt, $kind);
    }

    /** Saved manga in the order asked; stale when any is missing. */
    public function byIds(array $ids): Result
    {
        $found = Manga::query()->findMany($ids)->keyBy('id');
        $manga = collect($ids)->unique()->filter(fn (string $id) => $found->has($id))->map(fn (string $id) => $found[$id])->values();
        $result = Freshness::result($manga, $manga->min('fetched_at'), 'manga');

        return $manga->count() < count(array_unique($ids)) ? new Result($result->data, $result->fetchedAt, true) : $result;
    }

    /** Batches of up to 100 ids per call (Global Constraints), every content rating. */
    public function refreshByIds(array $ids, Priority $priority = Priority::Interactive): void
    {
        $size = config('readex.page_sizes.ids');

        foreach (array_chunk(array_values(array_unique($ids)), $size) as $chunk) {
            $response = $this->client->get('/manga', [
                'ids' => $chunk,
                'limit' => $size,
                'contentRating' => config('readex.content_ratings'),
                'includes' => ['cover_art'],
            ], $priority);

            DB::transaction(fn () => $this->storeManga($response));
        }
    }

    /** Stale while only a list call saved it: list calls don't include author and artist. */
    public function detail(string $id): Result
    {
        $manga = Manga::query()->find($id);
        $result = Freshness::result($manga, $manga?->fetched_at, 'manga');

        return $manga !== null && $manga->authors === null ? new Result($manga, $result->fetchedAt, true) : $result;
    }

    public function refreshDetail(string $id, Priority $priority = Priority::Interactive): void
    {
        $response = $this->client->get("/manga/{$id}", ['includes' => ['cover_art', 'author', 'artist']], $priority);

        DB::transaction(fn () => $this->storeManga(['data' => [$response['data']]]));
    }

    /**
     * Upserts the manga of a list response (`data` = manga entities). Home and recommendations reuse it.
     *
     * @return list<string> ids in response order
     */
    public function storeManga(array $response): array
    {
        $now = Carbon::now();
        $ids = [];

        foreach (MangaMapper::rows($response) as $row) {
            Manga::query()->updateOrCreate(['id' => $row['id']], [...$row, 'fetched_at' => $now]);
            $ids[] = $row['id'];
        }

        return $ids;
    }

    /** Deletes search/tag lists nobody refreshed for `list_keep_seconds`. Manga rows stay. */
    public function pruneLists(): int
    {
        $cutoff = Carbon::now()->subSeconds(config('readex.list_keep_seconds'));

        $keys = MangaListEntry::query()
            ->where('list_key', 'like', 'search:%')
            ->groupBy('list_key')
            ->havingRaw('max(fetched_at) < ?', [$cutoff->toDateTimeString()])
            ->pluck('list_key');

        return MangaListEntry::query()->whereIn('list_key', $keys)->delete();
    }

    /** @param  list<string>  $ids */
    private function appendBatch(string $key, int $batch, int $offset, array $ids): void
    {
        $known = MangaListEntry::query()->where('list_key', $key)->pluck('manga_id')->flip();
        $now = Carbon::now();
        $rows = [];

        // Page boundaries shift when MangaDex adds a series; a repeat keeps its first place.
        foreach ($ids as $i => $id) {
            if (! $known->has($id)) {
                $rows[] = ['list_key' => $key, 'batch' => $batch, 'position' => $offset + $i, 'manga_id' => $id, 'fetched_at' => $now];
                $known[$id] = true;
            }
        }

        MangaListEntry::query()->insert($rows);
    }

    private function searchQuery(string $query, array $tagIds, int $offset, int $limit): array
    {
        $query = trim($query);

        return array_filter([
            'title' => $query === '' ? null : $query,
            'includedTags' => array_values(array_unique($tagIds)) ?: null,
            'limit' => $limit,
            'offset' => $offset,
            'contentRating' => $this->settings->contentRatings(),
            'includes' => ['cover_art'],
            'order' => $query === '' ? ['followedCount' => 'desc'] : ['relevance' => 'desc'],
        ], fn ($value) => $value !== null);
    }

    private function hasMore(array $response, int $offset): bool
    {
        return $offset + count($response['data'] ?? []) < min($response['total'] ?? 0, self::MAX_RESULTS);
    }

    /** 0 = picked language or its sibling ("ES" badge), 1 = another chain language (English), 2 = none. Recommendations sort with it too. */
    public function rank(LanguageChain $chain, Manga $manga): int
    {
        return match (true) {
            $chain->badge($manga->available_languages) !== null => 0,
            $chain->covers($manga->available_languages) => 1,
            default => 2,
        };
    }
}
