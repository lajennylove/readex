<?php

namespace App\NativeComponents;

use App\Models\Manga;
use App\NativeComponents\Concerns\RefreshesData;
use App\NativeComponents\Concerns\ShowsResumeBar;
use App\NativeComponents\Concerns\ShowsTiles;
use App\NativeLayouts\HasResumeBar;
use App\Refresh\Refresh;
use App\Repositories\HomeRepository;
use App\Repositories\LatestUpdate;
use App\Repositories\Result;
use App\Repositories\SettingsRepository;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Spec §5.7: the three source rows (D37: no Continue reading row; the resume bar is the shortcut).
 * Each row refreshes on its own when empty or stale; an empty row keeps its place with
 * placeholder tiles until it loads (Review Focus 2).
 */
class Home extends NativeComponent implements HasResumeBar
{
    use RefreshesData;
    use ShowsResumeBar;
    use ShowsTiles;

    /** Row key => label, in screen order. */
    public const ROWS = [
        HomeRepository::SEASON => 'This season',
        HomeRepository::POPULAR => 'Popular new titles',
        HomeRepository::LATEST => 'Latest updates',
        HomeRepository::RECENTLY_ADDED => 'Recently added',
        HomeRepository::SELF_PUBLISHED => 'Self-published',
        HomeRepository::RECOMMENDED => 'Recommended for you',
    ];

    private const REFRESH = [
        HomeRepository::SEASON => 'refreshSeason',
        HomeRepository::POPULAR => 'refreshPopular',
        HomeRepository::LATEST => 'refreshLatest',
        HomeRepository::RECENTLY_ADDED => 'refreshRecentlyAdded',
        HomeRepository::SELF_PUBLISHED => 'refreshSelfPublished',
        HomeRepository::RECOMMENDED => 'refreshRecommended',
    ];

    /** The Recommended for you row is hidden until this many picks are stored (same gating as Season). */
    private const RECOMMENDED_MIN = 10;

    public function navTitle(): string
    {
        return 'Home';
    }

    public function mount(): void
    {
        $this->load();
    }

    protected function load(): void
    {
        $rows = [];

        foreach ($this->rows() as $key => $result) {
            $this->keepFresh($result, Refresh::of([HomeRepository::class, self::REFRESH[$key]]));
            $rows[] = $this->mangasOf($key, $result);
        }

        // Recommended's row is hidden from rows() until enough picks are stored, so we also
        // keep its refresh running on its own — the first visit of a user with enough signal
        // still needs the row to fill before it shows up.
        $home = app(HomeRepository::class);
        $this->keepFresh($home->recommended(), Refresh::of([HomeRepository::class, self::REFRESH[HomeRepository::RECOMMENDED]]));

        // Each row gets its own fair slice of the fetch-now budget (ShowsTiles::queueRowCovers):
        // a shared budget consumed in screen order starved Latest updates, since Season + Popular
        // alone already exceed it.
        $this->queueRowCovers($rows);
    }

    /** @return array<string, Result> row key => Result, in ROWS order */
    private function rows(): array
    {
        $home = app(HomeRepository::class);
        $seasonalOn = app(SettingsRepository::class)->seasonalUrl() !== null;
        $season = $seasonalOn ? $home->season() : null;

        // When the stored seasonal URL can't produce usable rows for the active source (e.g. a
        // demo-source URL left over after a swap to another source — refreshSeason catches the
        // 400/404 and clears the row), hide the row once we have tried and come back empty,
        // instead of showing permanent placeholder tiles. Still shown while loading or before
        // the first refresh so keepFresh() can start one.
        $sawEmptyAfterRefresh = isset($this->refreshedAt[HomeRepository::SEASON])
            && ! isset($this->refreshing[HomeRepository::SEASON])
            && ! isset($this->waiting[HomeRepository::SEASON]);

        if ($season !== null && ($season->data?->isEmpty() ?? true) && $sawEmptyAfterRefresh) {
            $season = null;
        }

        // Recommended row stays hidden until there are enough stored picks to feel curated.
        // Its refresh is kicked off separately in load() so a user whose signals eventually
        // produce 10+ picks will see the row appear without having to open it first.
        $recommended = $home->recommended();
        $recommended = ($recommended->data?->count() ?? 0) >= self::RECOMMENDED_MIN ? $recommended : null;

        return array_filter([
            HomeRepository::SEASON => $season,
            HomeRepository::POPULAR => $home->popular(),
            HomeRepository::LATEST => $home->latest(),
            HomeRepository::RECENTLY_ADDED => $home->recentlyAdded(),
            HomeRepository::SELF_PUBLISHED => $home->selfPublished(),
            HomeRepository::RECOMMENDED => $recommended,
        ]);
    }

    /** @return list<Manga> */
    private function mangasOf(string $key, Result $result): array
    {
        $data = $result->data ?? collect();

        return $key === HomeRepository::LATEST
            ? $data->map(fn (LatestUpdate $u) => $u->manga)->values()->all()
            : $data->values()->all();
    }

    public function render(): View
    {
        $chain = app(SettingsRepository::class)->chain();
        $rows = $this->rows();
        $tiles = [];

        foreach ($rows as $key => $result) {
            $tiles[$key] = $key === HomeRepository::LATEST
                ? ($result->data ?? collect())->map(fn (LatestUpdate $u) => $this->tile($u->manga, $chain, self::chapterLabel($u->chapter)))->values()->all()
                : array_map(fn (Manga $m) => $this->tile($m, $chain), $this->mangasOf($key, $result));
        }

        // §13 "no network, nothing saved": every row empty and nothing on its way.
        $nothingSaved = $this->problems !== [] && $this->refreshing === [] && $this->waiting === [] && array_filter($tiles) === [];

        return view('native.home', [
            'rows' => $tiles,
            'line' => $this->statusLine(),
            'nothingSaved' => $nothingSaved,
            'retrying' => $this->retryingOutage(),
        ]);
    }
}
