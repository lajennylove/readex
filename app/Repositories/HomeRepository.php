<?php

namespace App\Repositories;

use App\Adapters\Http\Client;
use App\Adapters\Http\Mappers\ChapterMapper;
use App\Adapters\Http\RateLimit\Priority;
use App\Models\Chapter;
use App\Models\Manga;
use App\Models\MangaListEntry;
use App\Models\ReadingProgress;
use App\Models\StoredChapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** The Home rows: Popular new titles, Latest updates. */
final class HomeRepository
{
    public const POPULAR = 'home.popular';

    public const LATEST = 'home.latest';

    public function __construct(
        private readonly Client $client,
        private readonly SettingsRepository $settings,
        private readonly MangaRepository $manga,
    ) {}

    public function popular(): Result
    {
        return $this->manga->readList(self::POPULAR, self::POPULAR);
    }

    /** Most followed series created in the last `popular_new_days` days, any language (reordered in get()). */
    public function refreshPopular(Priority $priority = Priority::Interactive): void
    {
        $response = $this->client->get('/manga', [
            // Start of the day, so the URL (and the shared-GET cache) stays the same all day.
            'createdAtSince' => Carbon::now()->utc()->subDays(config('readex.popular_new_days'))->startOfDay()->format('Y-m-d\TH:i:s'),
            'hasAvailableChapters' => true,
            'limit' => config('readex.page_sizes.home_row'),
            'contentRating' => $this->settings->contentRatings(),
            'includes' => ['cover_art'],
            'order' => ['followedCount' => 'desc'],
        ], $priority);

        DB::transaction(fn () => $this->replace(self::POPULAR, $this->manga->storeManga($response)));
    }

    /**
     * Newest first, one tile per series (its newest chapter), never reordered by language:
     * the row is already filtered to the chain (spec §10).
     *
     * @return Result data: Collection<int, LatestUpdate>
     */
    public function latest(): Result
    {
        $entries = MangaListEntry::query()->where('list_key', self::LATEST)->orderBy('position')->get();
        $manga = Manga::query()->findMany($entries->pluck('manga_id'))->keyBy('id');
        $chapters = Chapter::query()->findMany($entries->pluck('chapter_id')->filter())->keyBy('id');

        $updates = $entries
            ->filter(fn (MangaListEntry $e) => $manga->has($e->manga_id) && $chapters->has($e->chapter_id))
            ->map(fn (MangaListEntry $e) => new LatestUpdate($manga[$e->manga_id], $chapters[$e->chapter_id]))
            ->values();

        $fetchedAt = $entries->contains(fn (MangaListEntry $e) => $e->fetched_at === null) ? null : $entries->min('fetched_at');

        return Freshness::result($updates, $fetchedAt, self::LATEST);
    }

    /**
     * Two calls: the newest chapters in the chain languages (`page_sizes.latest`), then one
     * `/manga?ids[]` for the first `home_row` series (titles + covers).
     */
    public function refreshLatest(Priority $priority = Priority::Interactive): void
    {
        $response = $this->client->get('/chapter', [
            'translatedLanguage' => $this->settings->chain()->languages,
            // Scheduled releases can't be read yet.
            'includeFutureUpdates' => '0',
            'limit' => config('readex.page_sizes.latest'),
            'contentRating' => $this->settings->contentRatings(),
            'includes' => ['scanlation_group'],
            'order' => ['readableAt' => 'desc'],
        ], $priority);

        $newest = [];

        foreach (ChapterMapper::rows($response) as $row) {
            if ($row['manga_id'] !== null && ! isset($newest[$row['manga_id']])) {
                $newest[$row['manga_id']] = $row;
            }
        }

        $newest = array_slice($newest, 0, config('readex.page_sizes.home_row'), true);

        $manga = $newest === [] ? ['data' => []] : $this->client->get('/manga', [
            'ids' => array_keys($newest),
            'limit' => config('readex.page_sizes.ids'),
            'contentRating' => $this->settings->contentRatings(),
            'includes' => ['cover_art'],
        ], $priority);

        DB::transaction(function () use ($newest, $manga) {
            $this->manga->storeManga($manga);
            $old = MangaListEntry::query()->where('list_key', self::LATEST)->pluck('chapter_id')->filter();

            // fetched_at stays null until the series' feed is loaded, so ChapterRepository::get()
            // never shows a one-chapter feed. A row a feed already saved keeps its fetched_at.
            $rows = array_map(fn (array $row) => [...$row, 'fetched_at' => null], array_values($newest));
            Chapter::query()->upsert($rows, ['id'], array_values(array_diff(array_keys($rows[0] ?? []), ['id', 'fetched_at'])));

            $this->replace(self::LATEST, array_keys($newest), array_column($newest, 'id', 'manga_id'));
            $this->forgetChapters($old->diff(array_column($newest, 'id'))->values()->all());
        });
    }

    /**
     * Replaces a Home row with one batch in this order.
     *
     * @param  list<string>  $mangaIds
     * @param  array<string, string>  $chapterIds  manga id => chapter id (Latest updates)
     */
    private function replace(string $key, array $mangaIds, array $chapterIds = []): void
    {
        MangaListEntry::query()->where('list_key', $key)->delete();
        $now = Carbon::now();

        MangaListEntry::query()->insert(array_map(fn (string $id, int $position) => [
            'list_key' => $key,
            'batch' => 1,
            'position' => $position,
            'manga_id' => $id,
            'chapter_id' => $chapterIds[$id] ?? null,
            'fetched_at' => $now,
        ], $mangaIds, array_keys($mangaIds)));
    }

    /**
     * Chapters only the old Latest row knew about. Rows a feed loaded (fetched_at set), stored
     * chapters and chapters with reading progress stay.
     *
     * @param  list<string>  $ids
     */
    private function forgetChapters(array $ids): void
    {
        Chapter::query()
            ->whereIn('id', $ids)
            ->whereNull('fetched_at')
            ->whereNotIn('id', StoredChapter::query()->select('chapter_id'))
            ->whereNotIn('id', ReadingProgress::query()->select('chapter_id'))
            ->delete();
    }
}
