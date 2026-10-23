<?php

namespace App\NativeComponents\Concerns;

use App\Covers\CoverStore;
use App\MangaDex\RateLimit\Priority;
use App\Models\Manga;
use App\NativeLayouts\TabsLayout;

/**
 * For screens that show `native.partials.tile`. A tile's cover appears on the first render
 * after its file lands; RefreshesData's poll re-renders every 3 s, so no callback is needed.
 */
trait ShowsTiles
{
    /** Tile tap: the detail screen, pushed inside the tab this screen belongs to. */
    public function openManga(string $mangaId): void
    {
        $this->navigate(TabsLayout::mangaUri(TabsLayout::tabOf($this->nativeRouter?->currentUri()), $mangaId));
    }

    /**
     * Missing covers for these tiles, in screen order: the first `visible` at interactive
     * priority, the rest in the background.
     *
     * @param  list<Manga>  $mangas
     */
    protected function queueCovers(array $mangas): void
    {
        $visible = config('readex.covers.visible');
        $covers = app(CoverStore::class);

        $covers->queue(array_slice($mangas, 0, $visible), Priority::Interactive);
        $covers->queue(array_slice($mangas, $visible), Priority::Prefetch);
    }
}
