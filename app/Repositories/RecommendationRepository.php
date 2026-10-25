<?php

namespace App\Repositories;

use App\Adapters\Http\Client;
use App\Adapters\Http\RateLimit\Priority;
use App\Models\Manga;
use App\Models\Recommendation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** "More like this" on the detail screen (D16). */
final class RecommendationRepository
{
    public function __construct(
        private readonly Client $client,
        private readonly SettingsRepository $settings,
        private readonly MangaRepository $manga,
    ) {}

    /**
     * Recommended manga in the user's content ratings (filtered here, so a rating change needs no
     * refetch), in reading-language order (D17), highest score first inside each group. Stale when
     * a recommended manga row is gone.
     *
     * @return Result data: Collection<int, Manga>
     */
    public function get(string $mangaId): Result
    {
        $recommendations = Recommendation::query()->where('manga_id', $mangaId)->orderBy('position')->get();
        $manga = Manga::query()->findMany($recommendations->pluck('recommended_id'))->keyBy('id');
        $chain = $this->settings->chain();
        $ratings = $this->settings->contentRatings();
        $key = fn (Recommendation $r) => [$this->manga->rank($chain, $manga[$r->recommended_id]), -$r->score, $r->position];

        $list = $recommendations
            ->filter(fn (Recommendation $r) => $manga->has($r->recommended_id) && in_array($manga[$r->recommended_id]->content_rating, $ratings, true))
            ->sort(fn (Recommendation $a, Recommendation $b) => $key($a) <=> $key($b))
            ->map(fn (Recommendation $r) => $manga[$r->recommended_id])
            ->values();

        $result = Freshness::result($list, Manga::query()->find($mangaId)?->recommendations_fetched_at, 'recommendations');

        return $manga->count() < $recommendations->count() ? new Result($list, $result->fetchedAt, true) : $result;
    }

    /**
     * One call (MangaDex sends them all, no paging), then `/manga?ids[]` for titles and covers
     * with every content rating. Nothing is replaced until both calls are back.
     */
    public function refresh(string $mangaId, Priority $priority = Priority::Interactive): void
    {
        $response = $this->client->get("/manga/{$mangaId}/recommendation", [], $priority);
        $scores = [];

        // Each item links the source manga and the recommended one.
        foreach ($response['data'] ?? [] as $item) {
            foreach ($item['relationships'] ?? [] as $relation) {
                if ($relation['type'] === 'manga' && $relation['id'] !== $mangaId) {
                    $scores[$relation['id']] ??= (float) ($item['attributes']['score'] ?? 0);
                }
            }
        }

        $manga = [];
        $size = config('readex.page_sizes.ids');

        foreach (array_chunk(array_keys($scores), $size) as $chunk) {
            $manga[] = $this->client->get('/manga', [
                'ids' => $chunk,
                'limit' => $size,
                'contentRating' => config('readex.content_ratings'),
                'includes' => ['cover_art'],
            ], $priority);
        }

        DB::transaction(function () use ($mangaId, $scores, $manga) {
            $found = array_flip(array_merge(...array_map($this->manga->storeManga(...), $manga)));
            $now = Carbon::now();
            $rows = [];

            // API order (sorted by id, no ranking of its own); a manga /manga didn't return is left out.
            foreach ($scores as $id => $score) {
                if (isset($found[$id])) {
                    $rows[] = ['manga_id' => $mangaId, 'recommended_id' => $id, 'position' => count($rows), 'score' => $score, 'fetched_at' => $now];
                }
            }

            DB::table('recommendations')->where('manga_id', $mangaId)->delete();
            DB::table('recommendations')->insert($rows);
            Manga::query()->whereKey($mangaId)->update(['recommendations_fetched_at' => $now]);
        });
    }
}
