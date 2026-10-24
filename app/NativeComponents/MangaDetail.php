<?php

namespace App\NativeComponents;

use App\Downloads\Downloads;
use App\Downloads\DownloadState;
use App\Downloads\DownloadStatus;
use App\Language\Languages;
use App\Models\Chapter;
use App\Models\Collection as LibraryEntry;
use App\Models\Manga;
use App\Models\ReadingProgress;
use App\Models\Statistic;
use App\NativeComponents\Concerns\ManagesDownloads;
use App\NativeComponents\Concerns\RefreshesData;
use App\NativeComponents\Concerns\ShowsTiles;
use App\NativeLayouts\TabsLayout;
use App\Refresh\Refresh;
use App\Repositories\ChapterRepository;
use App\Repositories\ContinueReading;
use App\Repositories\LibraryChapter;
use App\Repositories\LibraryRepository;
use App\Repositories\MangaRepository;
use App\Repositories\RecommendationRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\StatisticsRepository;
use Illuminate\Support\Sleep;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Spec §5.7 / §11.3, D30: the one series screen for Home, Search and Library (no separate
 * Collection screen). Header (cover, people, status, rating, languages), Continue where the
 * reader left off (`LibraryRepository::resume()`), Favorite, description, More like this, then
 * the chapter list of one language in volume sections, the chapter Continue opens highlighted.
 * More like this sits above the chapters: under a 700-chapter list nobody would reach it.
 * A readable row asks Read or a download action; Download all sits under Favorite (§11.3).
 */
class MangaDetail extends NativeComponent
{
    use ManagesDownloads;
    use RefreshesData;
    use ShowsTiles;

    /** Navigation data: the chapter to open on top of this screen (`OpensChapters::showChapterViaDetail()`). */
    public const READ = 'read';

    public string $mangaId = '';

    public string $tab = 'home';

    /** Chapter-list language; '' until the series' languages are known. */
    public string $language = '';

    public function mount(string $id): void
    {
        $this->mangaId = $id;
        $this->tab = TabsLayout::tabOf($this->nativeRouter?->currentUri());
        $this->load();

        $chapter = Chapter::query()->find($this->data(self::READ));

        if ($chapter?->manga_id === $this->mangaId && $this->showChapter($chapter)) {
            Sleep::for(config('readex.reader.via_detail_settle_ms'))->milliseconds();
        }
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

        $similar = app(RecommendationRepository::class)->get($this->mangaId);
        $this->keepFresh($similar, $this->similarRefresh(), emptyIsAnswer: true);

        $this->queueCovers(array_values(array_filter([$detail->data, ...$similar->data->all()])));

        if ($this->language === '' || ! in_array($this->language, app(ChapterRepository::class)->languages($this->mangaId), true)) {
            $this->language = $this->openingLanguage();
        }

        if ($this->language !== '') {
            $this->keepFresh(
                app(ChapterRepository::class)->get($this->mangaId, $this->language),
                Refresh::of([ChapterRepository::class, 'refresh'], $this->mangaId, $this->language),
            );
        }
    }

    public function toggleFavorite(): void
    {
        app(LibraryRepository::class)->setFavorite($this->mangaId, ! $this->isFavorite());
    }

    public function resumeReading(): void
    {
        $current = app(LibraryRepository::class)->resume($this->mangaId);

        if ($current !== null) {
            $this->showChapter($current->chapter);
        }
    }

    /** Chapter row tap: a licensed chapter opens at the publisher, a readable one asks Read / Download first. */
    public function readChapter(string $chapterId): void
    {
        $chapter = Chapter::query()->find($chapterId);

        if ($chapter === null) {
            return;
        }

        $chapter->external_url === null ? $this->askChapterAction($chapter) : $this->showChapter($chapter);
    }

    public function downloadAll(): void
    {
        if ($this->language !== '') {
            $this->askDownloadAll($this->mangaId, $this->language);
        }
    }

    public function pickLanguage(string $language): void
    {
        if ($language === $this->language || ! in_array($language, app(ChapterRepository::class)->languages($this->mangaId), true)) {
            return;
        }

        $this->language = $language;
        $this->load();
    }

    public function render(): View
    {
        $manga = Manga::query()->find($this->mangaId);

        if ($manga === null) {
            return view('native.manga-detail', ['manga' => null, 'line' => $this->statusLine()]);
        }

        $chain = app(SettingsRepository::class)->chain();
        $chapters = app(ChapterRepository::class);
        $current = app(LibraryRepository::class)->resume($this->mangaId);
        $languages = $chapters->languages($this->mangaId);

        return view('native.manga-detail', [
            'manga' => $manga,
            'line' => $this->statusLine(),
            'title' => $chain->title($manga->title ?? [], $manga->alt_titles ?? []),
            'people' => self::people($manga->authors ?? [], $manga->artists ?? []),
            'facts' => implode(' · ', array_filter([$manga->status === null ? null : ucfirst($manga->status), $manga->year])),
            'stats' => self::stats(Statistic::query()->find($this->mangaId)),
            'languages' => implode(' · ', array_map(Languages::name(...), $languages)),
            'description' => $chain->description($manga->description ?? []),
            'similar' => app(RecommendationRepository::class)->get($this->mangaId)->data->map(fn (Manga $m) => $this->tile($m, $chain))->all(),
            'loadingSimilar' => $this->inFlight($this->similarRefresh()),
            'favorite' => $this->isFavorite(),
            'continue' => $this->continueLabel($current),
            'switch' => count($languages) > 1
                ? array_map(fn (string $code) => ['code' => $code, 'name' => Languages::name($code)], $languages)
                : [],
            'sections' => $this->language === '' ? [] : $this->chapterSections($current),
            'downloadAll' => $this->language === '' ? null : $this->downloadAllButton($this->mangaId, $this->language),
            'loadingChapters' => $this->language !== '' && $this->inFlight(Refresh::of([ChapterRepository::class, 'refresh'], $this->mangaId, $this->language)),
            'languageName' => $this->language === '' ? null : Languages::name($this->language),
        ]);
    }

    /**
     * Volume sections for the picked language. Once the Library knows the reader's group, one
     * upload per number (theirs first, `LibraryRepository::sections()`); before that, every
     * upload, so the reader can see which groups translate it.
     *
     * @return list<array{volume: string, chapters: list<array{id: string, label: string, caption: string, a11y: string, current: bool, read: bool, downloaded: bool}>}>
     */
    private function chapterSections(?ContinueReading $current): array
    {
        $library = app(LibraryRepository::class);

        if ($library->preferredGroup($this->mangaId) !== null) {
            $sections = array_map(fn (array $s) => [
                'volume' => $s['volume'],
                'chapters' => $s['chapters']->map(fn (LibraryChapter $c) => [$c->chapter, $c->progress]),
            ], $library->sections($this->mangaId, $this->language));
        } else {
            $sections = app(ChapterRepository::class)->sections($this->mangaId, $this->language);
            $ids = collect($sections)->flatMap(fn (array $s) => $s['chapters']->pluck('id'));
            $progress = ReadingProgress::query()->whereIn('chapter_id', $ids)->get()->keyBy('chapter_id');
            $sections = array_map(fn (array $s) => [
                'volume' => $s['volume'],
                'chapters' => $s['chapters']->map(fn (Chapter $c) => [$c, $progress->get($c->id)]),
            ], $sections);
        }

        $downloads = app(Downloads::class)->statuses(collect($sections)->flatMap(fn (array $s) => $s['chapters']->map(fn (array $pair) => $pair[0]->id))->all());

        return array_map(fn (array $s) => [
            'volume' => $s['volume'] === null ? 'No volume yet' : "Volume {$s['volume']}",
            'chapters' => $s['chapters']->map(fn (array $pair) => self::chapterRow($pair[0], $pair[1], $current?->chapter->id === $pair[0]->id, $downloads[$pair[0]->id]))->values()->all(),
        ], $sections);
    }

    /**
     * "Ch. 12 · Title" over "Group · Page 5 of 20" / "Group · Read" / "Group · Up next"; a
     * licensed chapter says where it opens instead of its status, a queued or downloaded one its
     * download state ("Downloading 45%"). A finished chapter stays dimmed either way.
     *
     * @return array{id: string, label: string, caption: string, a11y: string, current: bool, read: bool, downloaded: bool}
     */
    public static function chapterRow(Chapter $chapter, ?ReadingProgress $progress, bool $current, ?DownloadStatus $download = null): array
    {
        $label = implode(' · ', array_filter([self::chapterLabel($chapter), $chapter->title]));
        $downloadState = $download?->caption();

        $status = match (true) {
            $chapter->external_url !== null => 'Opens on '.(parse_url($chapter->external_url, PHP_URL_HOST) ?: 'the web'),
            $downloadState !== null => $downloadState,
            $progress?->finished_at !== null => 'Read',
            $progress !== null => 'Page '.($progress->page + 1).' of '.$progress->page_count,
            $current => 'Up next',
            default => null,
        };

        return [
            'id' => $chapter->id,
            'label' => $label,
            'caption' => implode(' · ', array_filter([$chapter->group_name ?? 'No group', $status])),
            'a11y' => $downloadState === null ? $label : "{$label}, {$downloadState}",
            'current' => $current,
            'read' => $progress?->finished_at !== null,
            'downloaded' => $download?->state === DownloadState::Downloaded,
        ];
    }

    /** The resumed chapter's language, else the first chain language the series has, else its first language. */
    private function openingLanguage(): string
    {
        $chapters = app(ChapterRepository::class);

        return app(LibraryRepository::class)->resume($this->mangaId)?->chapter->language
            ?? $chapters->defaultLanguage($this->mangaId)
            ?? $chapters->languages($this->mangaId)[0]
            ?? '';
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

    private function similarRefresh(): Refresh
    {
        return Refresh::of([RecommendationRepository::class, 'refresh'], $this->mangaId);
    }

    private function isFavorite(): bool
    {
        return (bool) LibraryEntry::query()->find($this->mangaId)?->favorite;
    }

    /** "Continue · Ch. 12, page 5" on a started chapter, "Continue · Ch. 13" for the next one; null when never read or caught up. */
    private function continueLabel(?ContinueReading $next): ?string
    {
        if ($next === null) {
            return null;
        }

        $label = 'Continue · '.self::chapterLabel($next->chapter);

        return $next->progress === null ? $label : $label.', page '.($next->progress->page + 1);
    }
}
