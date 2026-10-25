<?php

namespace App\Repositories;

use App\Adapters\Http\Client;
use App\Adapters\Http\RateLimit\Priority;
use App\Models\Statistic;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Ratings and follows for tiles, plus the rating distribution on the detail screen (D16). */
final class StatisticsRepository
{
    public function __construct(private readonly Client $client) {}

    /**
     * Saved statistics keyed by manga id; stale when any id is missing.
     *
     * @param  list<string>  $ids
     * @return Result data: Collection<string, Statistic>
     */
    public function forManga(array $ids): Result
    {
        $found = Statistic::query()->findMany($ids)->keyBy('manga_id');
        $result = Freshness::result($found, $found->min('fetched_at'), 'statistics');

        return $found->count() < count(array_unique($ids)) ? new Result($found, $result->fetchedAt, true) : $result;
    }

    /**
     * `manga[]` in batches of up to 100 (Global Constraints). All calls run before anything is
     * saved. A distribution saved by refreshDetail() stays.
     *
     * @param  list<string>  $ids
     */
    public function refresh(array $ids, Priority $priority = Priority::Interactive): void
    {
        $responses = [];

        foreach (array_chunk(array_values(array_unique($ids)), config('readex.page_sizes.ids')) as $chunk) {
            $responses[] = $this->client->get('/statistics/manga', ['manga' => $chunk], $priority);
        }

        DB::transaction(function () use ($responses) {
            foreach ($responses as $response) {
                $this->store($response['statistics'] ?? [], withDistribution: false);
            }
        });
    }

    /** One manga; stale while only the batched numbers (no distribution) are saved. */
    public function detail(string $id): Result
    {
        $statistic = Statistic::query()->find($id);
        $result = Freshness::result($statistic, $statistic?->fetched_at, 'statistics');

        return $statistic?->distribution === null ? new Result($statistic, $result->fetchedAt, true) : $result;
    }

    public function refreshDetail(string $id, Priority $priority = Priority::Interactive): void
    {
        $response = $this->client->get("/statistics/manga/{$id}", [], $priority);

        DB::transaction(fn () => $this->store($response['statistics'] ?? [], withDistribution: true));
    }

    /** @param  array<string, array<string, mixed>>  $statistics  manga id => source statistics */
    private function store(array $statistics, bool $withDistribution): void
    {
        $now = Carbon::now();

        foreach ($statistics as $mangaId => $item) {
            $values = [
                // Null when nobody rated the series yet.
                'rating_avg' => $item['rating']['average'] ?? null,
                'rating_bayesian' => $item['rating']['bayesian'] ?? null,
                'follows' => (int) ($item['follows'] ?? 0),
                'fetched_at' => $now,
            ];

            if ($withDistribution) {
                $values['distribution'] = array_map('intval', $item['rating']['distribution'] ?? []);
            }

            Statistic::query()->updateOrCreate(['manga_id' => (string) $mangaId], $values);
        }
    }
}
