<?php

namespace App\Repositories;

use App\Adapters\Http\Client;
use App\Adapters\Http\Exceptions\ApiError;
use App\Adapters\Http\Mappers\ChapterMapper;
use App\Adapters\Http\RateLimit\Priority;
use App\Models\Chapter;
use App\Models\Manga;
use App\Models\MangaListEntry;
use App\Models\ReadingProgress;
use App\Models\StoredChapter;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use JsonException;
use UnexpectedValueException;

/** The Home rows (spec §9, D15): This season, Popular new titles, Latest updates. */
final class HomeRepository
{
    public const SEASON = 'home.season';

    public const POPULAR = 'home.popular';

    public const LATEST = 'home.latest';

    public function __construct(
        private readonly Client $client,
        private readonly SettingsRepository $settings,
        private readonly MangaRepository $manga,
    ) {}

    /** In reading-language order (D17), like search results. */
    public function season(): Result
    {
        return $this->manga->readList(self::SEASON, self::SEASON);
    }

    /**
     * Ids from the community JSON (its list id is remembered); when the JSON fails, the
     * remembered source list (Review Focus 6). No remembered id: the JSON error bubbles
     * up and the row stays as it was. Most followed first, the JSON itself has no ranking.
     */
    public function refreshSeason(Priority $priority = Priority::Interactive): void
    {
        // Opt-in: no curated-list URL set → the row doesn't exist. We clear the stored list
        // so Home hides the row entirely (its own empty state would otherwise read "nothing
        // new yet", which isn't the right story for a user who hasn't opted in).
        if ($this->settings->seasonalUrl() === null) {
            $this->transactional(fn () => $this->replace(self::SEASON, []));

            return;
        }

        try {
            try {
                $ids = $this->seasonFromJson($priority);
            } catch (ApiError|ConnectionException|JsonException|UnexpectedValueException $e) {
                // Hard 400/404/405 on the seasonal JSON itself (e.g. the stored URL is dead against
                // the current source): degrade silently to a no-op, same shape as "no URL set".
                if ($e instanceof ApiError && in_array($e->status, [400, 404, 405], true)) {
                    $this->transactional(fn () => $this->replace(self::SEASON, []));

                    return;
                }

                $listId = $this->settings->seasonalListId() ?? throw $e;
                $ids = $this->seasonFromList($listId, $priority);
            }

            $manga = [];

            foreach (array_chunk($ids, config('readex.page_sizes.ids')) as $chunk) {
                $manga[] = $this->client->get('/manga', [
                    'ids' => $chunk,
                    'limit' => config('readex.page_sizes.ids'),
                    'contentRating' => $this->settings->contentRatings(),
                    'includes' => ['cover_art'],
                    'order' => ['followedCount' => 'desc'],
                ], $priority);
            }
        } catch (ApiError $e) {
            // The chained /manga?ids[]= call rejected the saved ids (e.g. demo-source ids after
            // the user swapped to another source): drop the stale list so Home hides the row
            // rather than surfacing "Something went wrong". The user's saved URL is untouched.
            if (in_array($e->status, [400, 404, 405], true)) {
                $this->transactional(fn () => $this->replace(self::SEASON, []));

                return;
            }

            throw $e;
        }

        $this->transactional(function () use ($manga) {
            $this->replace(self::SEASON, array_merge(...array_map($this->manga->storeManga(...), $manga)));
        });
    }

    public function popular(): Result
    {
        return $this->manga->readList(self::POPULAR, self::POPULAR);
    }

    /** Most followed series created in the last `popular_new_days` days, any language (reordered in get()). */
    public function refreshPopular(Priority $priority = Priority::Interactive): void
    {
        try {
            $response = $this->client->get('/manga', [
                // Start of the day, so the URL (and the shared-GET cache) stays the same all day.
                'createdAtSince' => Carbon::now()->utc()->subDays(config('readex.popular_new_days'))->startOfDay()->format('Y-m-d\TH:i:s'),
                'hasAvailableChapters' => true,
                'limit' => config('readex.page_sizes.home_row'),
                'contentRating' => $this->settings->contentRatings(),
                'includes' => ['cover_art'],
                'order' => ['followedCount' => 'desc'],
            ], $priority);
        } catch (ApiError $e) {
            // A source that doesn't serve /manga (e.g. a demo URL without the API suffix)
            // shouldn't surface as "Something went wrong"; leave the row empty instead.
            if (in_array($e->status, [404, 405], true)) {
                $this->transactional(fn () => $this->replace(self::POPULAR, []));

                return;
            }

            throw $e;
        }

        $this->transactional(fn () => $this->replace(self::POPULAR, $this->manga->storeManga($response)));
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
        try {
            $response = $this->client->get('/chapter', [
                'translatedLanguage' => $this->settings->chain()->languages,
                // Scheduled releases can't be read yet.
                'includeFutureUpdates' => '0',
                'limit' => config('readex.page_sizes.latest'),
                'contentRating' => $this->settings->contentRatings(),
                'includes' => ['scanlation_group'],
                'order' => ['readableAt' => 'desc'],
            ], $priority);
        } catch (ApiError $e) {
            // A source that doesn't serve /chapter (e.g. a demo URL without the API suffix)
            // shouldn't surface as "Something went wrong"; leave the row empty instead.
            if (in_array($e->status, [404, 405], true)) {
                $this->transactional(fn () => $this->replace(self::LATEST, []));

                return;
            }

            throw $e;
        }

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

        $this->transactional(function () use ($newest, $manga) {
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

    /** @return list<string> */
    private function seasonFromJson(Priority $priority): array
    {
        $url = $this->settings->seasonalUrl();
        if ($url === null) {
            throw new UnexpectedValueException('No curated-list URL configured.');
        }
        $json = $this->client->getJson($url, $priority);
        $ids = $json['manga_ids'] ?? null;

        if (! is_array($ids) || ! is_string($json['id'] ?? null)) {
            throw new UnexpectedValueException('The seasonal JSON has no list id or manga ids.');
        }

        $this->settings->setSeasonalListId($json['id']);

        return array_values(array_unique(array_filter($ids, 'is_string')));
    }

    /** @return list<string> */
    private function seasonFromList(string $listId, Priority $priority): array
    {
        $list = $this->client->get("/list/{$listId}", [], $priority);
        $ids = [];

        foreach ($list['data']['relationships'] ?? [] as $relation) {
            if ($relation['type'] === 'manga') {
                $ids[] = $relation['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Replaces a Home row with one batch in this order.
     *
     * @param  list<string>  $mangaIds
     * @param  array<string, string>  $chapterIds  manga id => chapter id (Latest updates)
     */
    /**
     * `DB::transaction($work)` with a one-shot retry when the first attempt hits a
     * transient SQLite I/O error (code 10) or a stale savepoint from a prior aborted
     * transaction on this connection. Multiple NativePHP async workers may race on the
     * same SQLite file; the fresh connection on retry resets `transactionLevel`.
     * A persistent failure stays silent — the data was already pulled, Freshness
     * reads it stale next tick and the row refreshes again.
     */
    private function transactional(Closure $work): void
    {
        try {
            DB::transaction($work);

            return;
        } catch (QueryException $e) {
            if (! self::isTransient($e)) {
                throw $e;
            }
        }

        DB::disconnect();

        try {
            DB::transaction($work);
        } catch (QueryException $e) {
            if (! self::isTransient($e)) {
                throw $e;
            }
        }
    }

    private static function isTransient(QueryException $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, 'disk I/O error')
            || str_contains($msg, 'savepoint')
            || str_contains($msg, 'within a transaction')
            || str_contains($msg, 'database is locked');
    }

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
