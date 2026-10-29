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
        HomeRepository::CATALOG => 'Browse',
        HomeRepository::POPULAR => 'Popular new titles',
        HomeRepository::LATEST => 'Latest updates',
        HomeRepository::RECENTLY_ADDED => 'Recently added',
        HomeRepository::SELF_PUBLISHED => 'Self-published',
        HomeRepository::RECOMMENDED => 'Recommended for you',
    ];

    private const REFRESH = [
        HomeRepository::SEASON => 'refreshSeason',
        HomeRepository::CATALOG => 'refreshCatalog',
        HomeRepository::POPULAR => 'refreshPopular',
        HomeRepository::LATEST => 'refreshLatest',
        HomeRepository::RECENTLY_ADDED => 'refreshRecentlyAdded',
        HomeRepository::SELF_PUBLISHED => 'refreshSelfPublished',
        HomeRepository::RECOMMENDED => 'refreshRecommended',
    ];

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

        // Each row gets its own fair slice of the fetch-now budget (ShowsTiles::queueRowCovers):
        // a shared budget consumed in screen order starved Latest updates, since Season + Popular
        // alone already exceed it.
        $this->queueRowCovers($rows);
    }

    /** @return array<string, Result> row key => Result, in ROWS order */
    private function rows(): array
    {
        $home = app(HomeRepository::class);

        $rows = [
            HomeRepository::SEASON => $home->season(),
            HomeRepository::CATALOG => $home->catalog(),
            HomeRepository::POPULAR => $home->popular(),
            HomeRepository::LATEST => $home->latest(),
            HomeRepository::RECENTLY_ADDED => $home->recentlyAdded(),
            HomeRepository::SELF_PUBLISHED => $home->selfPublished(),
            HomeRepository::RECOMMENDED => $home->recommended(),
        ];

        // "This season" is opt-in: a user who hasn't configured a seasonal URL (and whose last
        // refresh produced zero titles) gets no row at all, rather than a sticky empty one.
        if ($rows[HomeRepository::SEASON]->data?->isEmpty()
            && app(\App\Sources\ActiveSource::class)->seasonalUrl() === null) {
            unset($rows[HomeRepository::SEASON]);
        }

        // Browse is powered by `/manga/_catalog` — a demo-shape endpoint that static sources
        // precompute and dynamic REST sources don't publish. SourceMetaProber writes
        // `source_is_static_json` on each URL change (true when /_meta.json says so, false
        // when the probe answered otherwise or didn't answer). We hide the row for a known-
        // dynamic source and keep it (with loading placeholders) while unknown or static.
        if (app(\App\Repositories\SettingsRepository::class)->sourceIsStaticJson() === false) {
            unset($rows[HomeRepository::CATALOG]);
        }

        // Recommended for you stays hidden until the local signals produce enough tiles to be
        // worth showing — the row refreshes in the background either way (so it fills in as the
        // user reads more), but a 2-tile row is a worse surface than no row at all.
        if (($rows[HomeRepository::RECOMMENDED]->data?->count() ?? 0) < HomeRepository::RECOMMENDED_MIN) {
            unset($rows[HomeRepository::RECOMMENDED]);
        }

        return $rows;
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
