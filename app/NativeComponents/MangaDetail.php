<?php

namespace App\NativeComponents;

use App\Language\Languages;
use App\Models\Collection as LibraryEntry;
use App\Models\Manga;
use App\Models\Statistic;
use App\NativeComponents\Concerns\RefreshesData;
use App\NativeComponents\Concerns\ShowsTiles;
use App\NativeLayouts\TabsLayout;
use App\Refresh\Refresh;
use App\Repositories\ChapterRepository;
use App\Repositories\LibraryRepository;
use App\Repositories\MangaRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\StatisticsRepository;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Dialog;

/**
 * Spec §5.7 / §11.3, D30: the one series screen for Home, Search and Library (no separate
 * Collection screen). Header (cover, people, status, rating, languages), Continue where the
 * reader left off (`LibraryRepository::resume()`), Favorite, description.
 */
class MangaDetail extends NativeComponent
{
    use RefreshesData;
    use ShowsTiles;

    /** Choice 3: chapter and Continue taps until Phase 5 brings the reader. */
    public const READER_LATER = 'Reader comes in the next phase';

    public string $mangaId = '';

    public string $tab = 'home';

    public function mount(string $id): void
    {
        $this->mangaId = $id;
        $this->tab = TabsLayout::tabOf($this->nativeRouter?->currentUri());
        $this->load();
    }

    /** Never empty: iOS tabs drop the NavigationStack for an untitled screen and show the tab root instead. */
    public function navTitle(): string
    {
        $manga = Manga::query()->find($this->mangaId);

        if ($manga === null) {
            return 'Series';
        }

        return app(SettingsRepository::class)->chain()->title($manga->title ?? [], $manga->alt_titles ?? []);
    }

    protected function load(): void
    {
        $detail = app(MangaRepository::class)->detail($this->mangaId);

        $this->keepFresh($detail, Refresh::of([MangaRepository::class, 'refreshDetail'], $this->mangaId));
        $this->keepFresh(app(StatisticsRepository::class)->detail($this->mangaId), Refresh::of([StatisticsRepository::class, 'refreshDetail'], $this->mangaId));

        if ($detail->data !== null) {
            $this->queueCovers([$detail->data]);
        }
    }

    public function toggleFavorite(): void
    {
        app(LibraryRepository::class)->setFavorite($this->mangaId, ! $this->isFavorite());
    }

    public function resumeReading(): void
    {
        Dialog::toast(self::READER_LATER);
    }

    public function render(): View
    {
        $manga = Manga::query()->find($this->mangaId);

        if ($manga === null) {
            return view('native.manga-detail', ['manga' => null, 'line' => $this->statusLine()]);
        }

        $chain = app(SettingsRepository::class)->chain();

        return view('native.manga-detail', [
            'manga' => $manga,
            'line' => $this->statusLine(),
            'title' => $chain->title($manga->title ?? [], $manga->alt_titles ?? []),
            'people' => self::people($manga->authors ?? [], $manga->artists ?? []),
            'facts' => implode(' · ', array_filter([$manga->status === null ? null : ucfirst($manga->status), $manga->year])),
            'stats' => self::stats(Statistic::query()->find($this->mangaId)),
            'languages' => implode(' · ', array_map(Languages::name(...), app(ChapterRepository::class)->languages($this->mangaId))),
            'description' => $chain->description($manga->description ?? []),
            'favorite' => $this->isFavorite(),
            'continue' => $this->continueLabel(),
        ]);
    }

    /**
     * "Story & art: A" when the same people wrote and drew it, else "Story: …" and "Art: …".
     *
     * @param  list<string>  $authors
     * @param  list<string>  $artists
     * @return list<string>
     */
    public static function people(array $authors, array $artists): array
    {
        if ($authors !== [] && $authors === $artists) {
            return ['Story & art: '.implode(', ', $authors)];
        }

        return array_values(array_filter([
            $authors === [] ? null : 'Story: '.implode(', ', $authors),
            $artists === [] ? null : 'Art: '.implode(', ', $artists),
        ]));
    }

    /** "★ 8.32 · 40,308 follows" (Bayesian rating, as MangaDex shows it); null before statistics load. */
    public static function stats(?Statistic $statistic): ?string
    {
        if ($statistic === null) {
            return null;
        }

        $rating = $statistic->rating_bayesian ?? $statistic->rating_avg;
        $follows = number_format($statistic->follows).($statistic->follows === 1 ? ' follow' : ' follows');

        return ($rating === null ? 'No ratings yet' : '★ '.number_format($rating, 2)).' · '.$follows;
    }

    private function isFavorite(): bool
    {
        return (bool) LibraryEntry::query()->find($this->mangaId)?->favorite;
    }

    /** "Continue · Ch. 12, page 5" on a started chapter, "Continue · Ch. 13" for the next one; null when never read or caught up. */
    private function continueLabel(): ?string
    {
        $next = app(LibraryRepository::class)->resume($this->mangaId);

        if ($next === null) {
            return null;
        }

        $label = 'Continue · '.self::chapterLabel($next->chapter);

        return $next->progress === null ? $label : $label.', page '.($next->progress->page + 1);
    }
}
