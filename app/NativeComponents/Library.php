<?php

namespace App\NativeComponents;

use App\Language\LanguageChain;
use App\NativeComponents\Concerns\ShowsResumeBar;
use App\NativeComponents\Concerns\ShowsTiles;
use App\NativeLayouts\HasResumeBar;
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
 * The Library (bugfix/0018 scope): horizontally-scrolling rows per status rather than a single
 * grid behind filter + sort chips. The resume bar handles "last opened"; the Reading row carries
 * every other started series. Rows below:
 *
 *   - Favorites        — every favorited series, latest favorite first.
 *   - New              — fully downloaded + no chapter opened yet, latest download first.
 *   - Downloaded       — every fully-downloaded series, latest download first.
 *   - Reading          — every series with any reading progress, latest read first,
 *                        excluding the one currently in the resume bar.
 *   - Interested       — "You may still be interested" row (D42), kept as-is.
 */
class Library extends NativeComponent implements HasResumeBar
{
    use ShowsResumeBar;
    use ShowsTiles;

    private const PIN = 'Pin';

    private const UNPIN = 'Unpin';

    private const REMOVE = 'Remove';

    public function navTitle(): string
    {
        return 'Library';
    }

    public function mount(): void
    {
        $this->queueTileCovers();
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
        $library = app(LibraryRepository::class);

        $resumeBarMangaId = $library->lastOpened()?->manga->id;

        $favorites = $library->grid('added', '', 'favorites');
        $downloaded = $library->grid('downloaded', '', 'downloaded');
        $new = $downloaded->filter(fn (LibraryTile $t) => $t->chips->new);
        $reading = $library->grid('opened', '', 'reading')
            ->reject(fn (LibraryTile $t) => $t->manga->id === $resumeBarMangaId)
            ->values();

        $toTiles = fn (Collection $rows) => $rows->map(fn (LibraryTile $t) => $this->libraryTile($t, $chain))->values()->all();

        return view('native.library', [
            'favorites' => $toTiles($favorites),
            'new' => $toTiles($new),
            'downloaded' => $toTiles($downloaded),
            'reading' => $toTiles($reading),
            'interested' => app(ViewedSeriesRepository::class)->list()->map(fn ($m) => $this->tile($m, $chain, longPress: 'removeFromInterestRow'))->all(),
        ]);
    }

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
        $library = app(LibraryRepository::class);
        $resumeBarMangaId = $library->lastOpened()?->manga->id;

        $favorites = $library->grid('added', '', 'favorites')->map(fn (LibraryTile $t) => $t->manga)->all();
        $downloaded = $library->grid('downloaded', '', 'downloaded')->map(fn (LibraryTile $t) => $t->manga)->all();
        $reading = $library->grid('opened', '', 'reading')
            ->reject(fn (LibraryTile $t) => $t->manga->id === $resumeBarMangaId)
            ->map(fn (LibraryTile $t) => $t->manga)
            ->all();
        $interested = app(ViewedSeriesRepository::class)->list()->all();

        $this->queueRowCovers([$favorites, $downloaded, $reading, $interested]);
    }
}
