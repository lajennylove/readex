<?php

namespace App\NativeComponents\Concerns;

use App\Covers\CoverStore;
use App\Language\LanguageChain;
use App\MangaDex\RateLimit\Priority;
use App\Models\Chapter;
use App\Models\Manga;
use App\NativeLayouts\TabsLayout;
use App\Repositories\ContinueReading;
use App\Repositories\LibraryRepository;
use Illuminate\Support\Collection;

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

    /** "Ch. 12", or "Oneshot" for a chapter without a number. */
    public static function chapterLabel(Chapter $chapter): string
    {
        return $chapter->number === null ? 'Oneshot' : "Ch. {$chapter->number}";
    }

    /**
     * Missing covers for these tiles, in screen order: the first `visible` downloaded right away
     * (FetchCovers), the rest queued in the background.
     *
     * @param  list<Manga>  $mangas
     */
    protected function queueCovers(array $mangas): void
    {
        $visible = config('readex.covers.visible');
        $covers = app(CoverStore::class);

        $covers->fetch(array_slice($mangas, 0, $visible));
        $covers->queue(array_slice($mangas, $visible), Priority::Prefetch);
    }

    /**
     * What `native.partials.tile` needs; title and badge follow the reading chain (spec §10).
     *
     * @return array{manga: Manga, title: string, badge: ?string, caption: ?string}
     */
    protected function tile(Manga $manga, LanguageChain $chain, ?string $caption = null): array
    {
        return [
            'manga' => $manga,
            'title' => $chain->title($manga->title ?? [], $manga->alt_titles ?? []),
            'badge' => $chain->badge($manga->available_languages ?? []),
            'caption' => $caption,
        ];
    }

    /** @return Collection<int, ContinueReading> */
    protected function continueReading(): Collection
    {
        return app(LibraryRepository::class)->continueReading(config('readex.page_sizes.home_row'));
    }

    /** "Ch. 12 · 40%" while reading, "Ch. 13 · Up next" for the next, unopened chapter. */
    protected function continueTile(ContinueReading $next, LanguageChain $chain): array
    {
        $progress = $next->progress;
        $state = $progress === null || $progress->page_count === 0
            ? 'Up next'
            : intdiv(($progress->page + 1) * 100, $progress->page_count).'%';

        return $this->tile($next->manga, $chain, self::chapterLabel($next->chapter)." · {$state}");
    }
}
