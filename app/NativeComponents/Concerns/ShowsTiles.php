<?php

namespace App\NativeComponents\Concerns;

use App\Adapters\Http\RateLimit\Priority;
use App\Covers\CoverStore;
use App\Language\LanguageChain;
use App\Models\Chapter;
use App\Models\Manga;
use App\NativeLayouts\TabsLayout;
use App\Repositories\ContinueReading;
use App\Repositories\LibraryRepository;
use Illuminate\Support\Collection;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Facades\Dialog;

/**
 * For screens that show `native.partials.tile`. A tile's cover appears on the first render
 * after its file lands; RefreshesData's poll re-renders every 3 s, so no callback is needed.
 */
trait ShowsTiles
{
    use OpensChapters;

    /** Tile tap: the detail screen, pushed inside the tab this screen belongs to. */
    public function openManga(string $mangaId): void
    {
        $this->navigate(TabsLayout::mangaUri(TabsLayout::tabOf($this->nativeRouter?->currentUri()), $mangaId));
    }

    /** Continue reading tile tap: the series' Detail, which opens the chapter. */
    public function openChapter(string $chapterId): void
    {
        $chapter = Chapter::query()->find($chapterId);

        if ($chapter !== null) {
            $this->showChapterViaDetail($chapter);
        }
    }

    /**
     * Library tile hold: confirm before taking the series out of the Library (destructive, so
     * it asks first — same convention as `ManagesDownloads::askChapterAction()`'s Remove).
     */
    public function askRemoveFromLibrary(string $mangaId): void
    {
        Dialog::alert(
            'Remove from library',
            '',
            [['label' => 'Cancel', 'style' => 'cancel'], ['label' => 'Remove', 'style' => 'destructive']],
        )->buttonPressed(fn (ButtonPressed $pressed) => $pressed->label === 'Remove'
            ? app(LibraryRepository::class)->removeFromLibrary($mangaId) : null);
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
        $this->queueRowCovers([$mangas]);
    }

    /**
     * Missing covers for several rows shown at once (screen order), each given its own equal
     * slice of `covers.visible` as a `fetch()` budget: the row's first `covers.visible / count($rows)`
     * tiles download right away, the rest of every row is queued together in the background.
     *
     * A single shared budget consumed first-come, first-served by whichever row the caller lists
     * first would starve every later row — on Home, Season (often 65+ series) and Popular (20)
     * alone already exceed the old shared budget of 12, so Latest updates' covers never got
     * fetched right away even though its tiles are on screen just like the others.
     *
     * @param  list<list<Manga>>  $rows
     */
    protected function queueRowCovers(array $rows): void
    {
        $covers = app(CoverStore::class);

        if ($rows === []) {
            return;
        }

        $perRow = intdiv(config('readex.covers.visible'), count($rows));
        $background = [];

        foreach ($rows as $row) {
            $covers->fetch(array_slice($row, 0, $perRow));
            $background = [...$background, ...array_slice($row, $perRow)];
        }

        $covers->queue($background, Priority::Prefetch);
    }

    /**
     * What `native.partials.tile` needs; title and badge follow the reading chain (spec §10).
     * `$removable` offers a long-press "Remove from library" action (Library tiles only).
     *
     * @return array{manga: Manga, title: string, badge: ?string, caption: ?string, chapterId: ?string, removable: bool}
     */
    protected function tile(Manga $manga, LanguageChain $chain, ?string $caption = null, ?string $chapterId = null, bool $removable = false): array
    {
        return [
            'manga' => $manga,
            'title' => $chain->title($manga->title ?? [], $manga->alt_titles ?? []),
            'badge' => $chain->badge($manga->available_languages ?? []),
            'caption' => $caption,
            'chapterId' => $chapterId,
            'removable' => $removable,
        ];
    }

    /** @return Collection<int, ContinueReading> */
    protected function continueReading(): Collection
    {
        return app(LibraryRepository::class)->continueReading(config('readex.page_sizes.home_row'));
    }

    /** "Ch. 12 · 40%" while reading, "Ch. 13 · Up next" for the next, unopened chapter; opens that chapter (Kindle style), not the series. Holding it offers Remove from library, since every Continue reading series is a Library series. */
    protected function continueTile(ContinueReading $next, LanguageChain $chain): array
    {
        $progress = $next->progress;
        $state = $progress === null || $progress->page_count === 0
            ? 'Up next'
            : intdiv(($progress->page + 1) * 100, $progress->page_count).'%';

        return $this->tile($next->manga, $chain, self::chapterLabel($next->chapter)." · {$state}", $next->chapter->id, removable: true);
    }
}
