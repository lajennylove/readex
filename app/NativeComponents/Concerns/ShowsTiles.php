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
use Illuminate\Support\Facades\Log;
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
     * Missing covers for several rows shown at once (screen order): each row's fetch-now share
     * of `covers.visible` is sized to how many of its covers are actually missing on disk, not a
     * flat `covers.visible / count($rows)` split. A row with nothing missing (e.g. Season or
     * Popular on a day neither has changed) claims none of the budget, so a row with more missing
     * covers than its even share (typically Latest updates, which changes on almost every load)
     * gets the rest instead of it being wasted on redundant `fetch()` no-ops. The rest of every
     * row — whatever its fetch-now share didn't cover — is queued together in the background.
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

        $screen = class_basename(static::class);
        $missingByRow = array_map(fn (array $row) => $covers->missingCovers($row), $rows);
        $needs = array_map(fn (array $missing) => count($missing), $missingByRow);
        $budget = (int) config('readex.covers.visible');
        $budgets = self::allocateFetchBudget($needs, $budget);

        Log::channel('covers')->info('queueRowCovers', [
            'screen' => $screen,
            'row_sizes' => array_map(fn (array $r) => count($r), $rows),
            'needs' => $needs,
            'budget' => $budget,
            'allocated' => $budgets,
        ]);

        $background = [];

        foreach ($rows as $index => $row) {
            $fetchNow = array_slice($missingByRow[$index], 0, $budgets[$index]);
            $covers->fetch($fetchNow);

            $fetchNowIds = array_flip(array_map(fn (Manga $m) => $m->id, $fetchNow));
            $background = [...$background, ...array_values(array_filter($row, fn (Manga $m) => ! isset($fetchNowIds[$m->id])))];
        }

        Log::channel('covers')->info('queueRowCovers: background queue', ['count' => count($background)]);
        $covers->queue($background, Priority::Prefetch);
    }

    /**
     * One slot at a time to every row that still has missing covers and the total budget isn't
     * spent, round-robin in row order — so a row whose `$needs` entry hits 0 (every cover already
     * cached) simply stops claiming slots and the rest of the budget keeps circulating to rows
     * that still need it, instead of each row being capped at a flat `$budget / count($needs)`
     * regardless of how much it actually needs.
     *
     * @param  list<int>  $needs  each row's missing-cover count, in row order
     * @return list<int> each row's allocated fetch-now budget, same order, each <= its need
     */
    private static function allocateFetchBudget(array $needs, int $budget): array
    {
        $allocated = array_fill(0, count($needs), 0);

        while ($budget > 0 && array_sum($needs) > 0) {
            foreach ($needs as $index => $need) {
                if ($budget === 0) {
                    break;
                }

                if ($need > 0) {
                    $allocated[$index]++;
                    $needs[$index]--;
                    $budget--;
                }
            }
        }

        return $allocated;
    }

    /**
     * What `native.partials.tile` needs; title and badge follow the reading chain (spec §10).
     * `$removable` offers a long-press "Remove from library" action (Library tiles only).
     * `$longPress` overrides the long-press target method entirely ("You may still be
     * interested" row: `Library::removeFromInterestRow()`, not the Library-removal dialog).
     *
     * @return array{manga: Manga, title: string, badge: ?string, caption: ?string, chapterId: ?string, removable: bool, longPress: ?string}
     */
    protected function tile(Manga $manga, LanguageChain $chain, ?string $caption = null, ?string $chapterId = null, bool $removable = false, ?string $longPress = null): array
    {
        return [
            'manga' => $manga,
            'title' => $chain->title($manga->title ?? [], $manga->alt_titles ?? []),
            'badge' => $chain->badge($manga->available_languages ?? []),
            'caption' => $caption,
            'chapterId' => $chapterId,
            'removable' => $removable,
            'longPress' => $longPress,
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
