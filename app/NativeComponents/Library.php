<?php

namespace App\NativeComponents;

use App\Language\LanguageChain;
use App\NativeComponents\Concerns\ShowsResumeBar;
use App\NativeComponents\Concerns\ShowsTiles;
use App\NativeLayouts\HasResumeBar;
use App\Repositories\ContinueReading;
use App\Repositories\LibraryRepository;
use App\Repositories\LibraryTile;
use App\Repositories\SettingsRepository;
use App\Repositories\ViewedSeriesRepository;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Facades\Dialog;

/**
 * Spec §1: the Library grid — pinned series first, filter chips (All/Reading/Downloaded/
 * Favorites), a sort picker (Last opened/Recently downloaded/Date added/Title), a title
 * search field, and status chips per tile (Downloading/Downloaded/New/Finished). Below the
 * grid, the "You may still be interested" row (D41) for series only glanced at on Detail.
 */
class Library extends NativeComponent implements HasResumeBar
{
    use ShowsResumeBar;
    use ShowsTiles;

    private const PIN = 'Pin';

    private const UNPIN = 'Unpin';

    private const REMOVE = 'Remove';

    public string $filter = 'all';

    public string $sort = 'opened';

    /** The field's text as typed; a trimmed/normalized copy lives in `$search`. */
    public string $searchText = '';

    public string $search = '';

    public function navTitle(): string
    {
        return 'Library';
    }

    public function mount(): void
    {
        $this->queueTileCovers();
    }

    public function setFilter(string $filter): void
    {
        if (in_array($filter, LibraryRepository::FILTERS, true)) {
            $this->filter = $filter;
            $this->queueTileCovers();
        }
    }

    public function setSort(string $sort): void
    {
        if (in_array($sort, LibraryRepository::SORTS, true)) {
            $this->sort = $sort;
            $this->queueTileCovers();
        }
    }

    /** The field's text, debounced on the phone. */
    public function searchFor(string $text): void
    {
        $this->searchText = $text;
        $this->search = preg_replace('/\s+/u', ' ', trim($text));
        $this->queueTileCovers();
    }

    public function clearSearch(): void
    {
        $this->searchText = '';
        $this->search = '';
    }

    /** Library tile hold: Pin/Unpin plus the existing destructive Remove. */
    public function askLibraryTileAction(string $mangaId): void
    {
        $library = app(LibraryRepository::class);
        $pinned = $library->grid()->first(fn (LibraryTile $t) => $t->manga->id === $mangaId)?->pinned ?? false;

        Dialog::alert('', '', [
            ['label' => 'Cancel', 'style' => 'cancel'],
            ['label' => $pinned ? self::UNPIN : self::PIN, 'style' => 'default'],
            ['label' => self::REMOVE, 'style' => 'destructive'],
        ])->buttonPressed(fn (ButtonPressed $pressed) => match ($pressed->label) {
            self::PIN => $library->setPinned($mangaId, true),
            self::UNPIN => $library->setPinned($mangaId, false),
            self::REMOVE => $library->removeFromLibrary($mangaId),
            default => null,
        });
    }

    /** "You may still be interested" row tile hold: long-press "Remove from this list" (D42). */
    public function removeFromInterestRow(string $mangaId): void
    {
        app(ViewedSeriesRepository::class)->remove($mangaId);
    }

    public function render(): View
    {
        $chain = app(SettingsRepository::class)->chain();
        $grid = $this->grid();

        return view('native.library', [
            'continue' => $this->continueReading()->map(fn (ContinueReading $c) => $this->continueTile($c, $chain))->all(),
            'tiles' => $grid->map(fn (LibraryTile $t) => $this->libraryTile($t, $chain))->all(),
            'interested' => app(ViewedSeriesRepository::class)->list()->map(fn ($m) => $this->tile($m, $chain, longPress: 'removeFromInterestRow'))->all(),
            'filter' => $this->filter,
            'filters' => LibraryRepository::FILTERS,
            'sort' => $this->sort,
            'sorts' => LibraryRepository::SORTS,
            'searchText' => $this->searchText,
        ]);
    }

    private function grid(): Collection
    {
        return app(LibraryRepository::class)->grid($this->sort, $this->search, $this->filter);
    }

    /**
     * A grid tile's own data: `ShowsTiles::tile()` plus pinned/chips/the status-chip labels to
     * show, in a fixed order so the row doesn't reflow between renders.
     */
    private function libraryTile(LibraryTile $tile, LanguageChain $chain): array
    {
        $chips = $tile->chips;
        $labels = array_values(array_filter([
            $chips->downloading ? 'Downloading' : null,
            $chips->downloaded ? 'Downloaded' : null,
            $chips->new ? 'New' : null,
            $chips->finished ? 'Finished' : null,
        ]));

        return [
            ...$this->tile($tile->manga, $chain, longPress: 'askLibraryTileAction'),
            'pinned' => $tile->pinned,
            'statusChips' => $labels,
            'newChapterAvailable' => $chips->newChapterAvailable,
        ];
    }

    private function queueTileCovers(): void
    {
        $this->queueCovers($this->grid()->map(fn (LibraryTile $t) => $t->manga)->all());
        $this->queueCovers(app(ViewedSeriesRepository::class)->list()->all());
    }
}
