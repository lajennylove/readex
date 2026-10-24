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
 * Spec §5.7: the three MangaDex rows (D37: no Continue reading row; the resume bar is the shortcut).
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
    ];

    private const REFRESH = [
        HomeRepository::SEASON => 'refreshSeason',
        HomeRepository::POPULAR => 'refreshPopular',
        HomeRepository::LATEST => 'refreshLatest',
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
        $mangas = [];

        foreach ($this->rows() as $key => $result) {
            $this->keepFresh($result, Refresh::of([HomeRepository::class, self::REFRESH[$key]]));
            $mangas = [...$mangas, ...$this->mangasOf($key, $result)];
        }

        $this->queueCovers($mangas);
    }

    /** @return array<string, Result> row key => Result, in ROWS order */
    private function rows(): array
    {
        $home = app(HomeRepository::class);

        return [
            HomeRepository::SEASON => $home->season(),
            HomeRepository::POPULAR => $home->popular(),
            HomeRepository::LATEST => $home->latest(),
        ];
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
