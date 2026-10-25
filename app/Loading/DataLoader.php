<?php

namespace App\Loading;

use App\Covers\CoverStore;
use App\MangaDex\Exceptions\ApiError;
use App\MangaDex\RateLimit\Priority;
use App\Models\Manga;
use App\Refresh\Outcome;
use App\Refresh\RefreshStatus;
use App\Repositories\HomeRepository;
use App\Repositories\LatestUpdate;
use App\Repositories\TagRepository;
use Closure;

/**
 * The first-run loading steps (spec §9 step 2), reused by "Refresh provider data" (§7.1).
 * Steps run in order and a failed step doesn't stop the others, except offline: once one
 * step finds no connection, the rest are marked offline without trying.
 */
final class DataLoader
{
    /** Step => label shown in the progress list. */
    public const STEPS = [
        'tags' => 'Categories',
        'season' => 'This season',
        'popular' => 'Popular new titles',
        'latest' => 'Latest updates',
        'covers' => 'Covers',
    ];

    /** The steps that fill Home rows. */
    public const HOME_ROWS = ['season', 'popular', 'latest'];

    public function __construct(
        private readonly TagRepository $tags,
        private readonly HomeRepository $home,
        private readonly CoverStore $covers,
    ) {}

    /**
     * Home rows always load as `interactive`; tags and covers use `$rest` (§7.1: the
     * refill sends everything after the rows as `bulk`).
     *
     * @param  Closure(string $step, string $state): void  $progress  state = LoadProgress::RUNNING or a RefreshStatus value
     * @return array<string, Outcome>
     */
    public function run(Closure $progress, Priority $rest = Priority::Interactive): array
    {
        $outcomes = [];
        $previous = null;

        foreach (array_keys(self::STEPS) as $step) {
            if ($previous?->status === RefreshStatus::Offline) {
                $outcome = $previous;
            } else {
                $progress($step, LoadProgress::RUNNING);
                $outcome = Outcome::capture(fn () => $this->step($step, $rest));
            }

            $progress($step, $outcome->status->value);
            $outcomes[$step] = $previous = $outcome;
        }

        return $outcomes;
    }

    private function step(string $step, Priority $rest): void
    {
        match ($step) {
            'tags' => $this->loadTags($rest),
            'season' => $this->home->refreshSeason(Priority::Interactive),
            'popular' => $this->home->refreshPopular(Priority::Interactive),
            'latest' => $this->home->refreshLatest(Priority::Interactive),
            'covers' => $this->loadCovers($rest),
        };
    }

    /** Setup already starts tags while the reader picks settings; don't load them twice. */
    private function loadTags(Priority $priority): void
    {
        $saved = $this->tags->get();

        if ($saved->isEmpty() || $saved->isStale) {
            $this->tags->refresh($priority);
        }
    }

    /**
     * The first `covers.visible` tiles Home shows, taken row by row in turns (each row
     * shows its first few tiles on screen). A broken cover is skipped; the rest of the
     * covers are queued by the screens themselves.
     */
    private function loadCovers(Priority $priority): void
    {
        foreach ($this->firstTiles(config('readex.covers.visible')) as $manga) {
            try {
                $this->covers->download($manga, $priority);
            } catch (ApiError) {
                continue;
            }
        }
    }

    /** @return list<Manga> */
    private function firstTiles(int $count): array
    {
        $rows = [
            $this->home->season()->data?->values()->all() ?? [],
            $this->home->popular()->data?->values()->all() ?? [],
            $this->home->latest()->data?->map(fn (LatestUpdate $u) => $u->manga)->values()->all() ?? [],
        ];

        $tiles = [];

        for ($i = 0; count($tiles) < $count && array_filter($rows, fn (array $row) => isset($row[$i])) !== []; $i++) {
            foreach ($rows as $row) {
                if (isset($row[$i]) && count($tiles) < $count) {
                    $tiles[$row[$i]->id] ??= $row[$i];
                }
            }
        }

        return array_values($tiles);
    }
}
