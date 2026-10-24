<?php

namespace App\NativeComponents;

use App\Models\Chapter;
use App\Models\StoredChapter;
use App\NativeLayouts\TabsLayout;
use App\Pages\LoadState;
use App\Pages\PageFetcher;
use App\Repositories\LibraryRepository;
use App\Storage\PageStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View as Views;
use Illuminate\View\View;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\Concerns\HasVirtualListWindow;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\Network;

/**
 * Spec §12, D33 choices 1, 3, 5: a black edge-to-edge strip, one virtual_list row per page.
 * Opens on the saved page; above it one row "Pages 1–N" (iOS can't scroll a list to a row) that
 * reloads the chapter from page 1. Tap a page → bar with "Ch. 12 · 5 / 43". Group credit at the
 * top and in the last row, which also has "Next: Ch. 13" or "You're caught up".
 *
 * Rows: 0 = header (credit, or "Pages 1–N"), then pages `$start` … last, then the end row.
 * The list only reports its window when the visible rows near its edge, so the page is the
 * window's first row + overscan, and the end row entering the window means the chapter was read
 * (about two pages before its last page).
 */
class Reader extends NativeComponent
{
    use HasVirtualListWindow;

    /** Rows the list keeps beyond the visible ones, each side. */
    public const OVERSCAN = 2;

    /** First window: header + 2 pages, so a 4-row list already reports a scroll. */
    public const FIRST_WINDOW = 2;

    public string $chapterId = '';

    public string $tab = 'home';

    /** First page in the list (0-based): the saved page; 0 after "Pages 1–N". */
    public int $start = 0;

    /** Page the reader is on (0-based). */
    public int $page = 0;

    public bool $barShown = false;

    /** ms of the last time the reader asked for its pages (offline retry). */
    public int $askedAt = 0;

    /** @var array{pages?: list<array{src: string, width: int, height: int}|null>, chapter?: Chapter} */
    private array $seen = [];

    public static function uriFor(string $tab, string $chapterId): string
    {
        return '/'.(in_array($tab, TabsLayout::DETAIL_TABS, true) ? $tab : 'home').'/read/'.$chapterId;
    }

    public function mount(string $chapterId): void
    {
        $this->chapterId = $chapterId;
        $this->tab = TabsLayout::tabOf($this->nativeRouter?->currentUri());

        $progress = app(LibraryRepository::class)->progressFor($chapterId);
        $this->start = $progress !== null && $progress->finished_at === null ? $progress->page : 0;
        $this->page = $this->start;
        $this->setVirtualWindow(0, self::FIRST_WINDOW);

        $this->markOpened();
        $this->askForPages();
    }

    /** Never empty: iOS tabs drop the NavigationStack for an untitled screen. */
    public function navTitle(): string
    {
        $chapter = $this->chapter();

        if ($chapter === null) {
            return 'Reader';
        }

        $count = $this->pageCount();

        return MangaDetail::chapterLabel($chapter).($count > 0 ? ' · '.(min($this->page, $count - 1) + 1)." / {$count}" : '');
    }

    public function shouldHideNavBar(): bool
    {
        return ! $this->barShown;
    }

    public function windowChanged(int $from, int $to): void
    {
        $this->forget();
        $this->setVirtualWindow($from, $to);
        $this->page = $this->pageFromWindow();
        $this->saveProgress();
        $this->askForPages();
    }

    public function toggleBar(): void
    {
        $this->barShown = ! $this->barShown;
    }

    /** "Pages 1–N": the whole chapter from page 1. */
    public function fromStart(): void
    {
        $this->forget();
        $this->start = 0;
        $this->page = 0;
        $this->setVirtualWindow(0, self::FIRST_WINDOW);
        $this->askForPages();
    }

    public function retryPage(int $index): void
    {
        $this->forget();
        app(PageFetcher::class)->retry($this->chapterId, $index);
        $this->askedAt = Carbon::now()->getTimestampMs();
    }

    /** "Needs internet" → Retry. */
    public function retry(): void
    {
        $this->forget();
        $this->askForPages();
    }

    public function nextChapter(): void
    {
        $this->forget();
        $chapter = $this->chapter();
        $next = $chapter === null ? null : app(LibraryRepository::class)->after($chapter);

        if ($next === null) {
            return;
        }

        $count = $this->pageCount();

        if ($count > 0 && app(PageStore::class)->manifest($this->chapterId) !== null) {
            app(LibraryRepository::class)->saveProgress($this->chapterId, $count - 1, $count);
        }

        if ($next->external_url !== null) {
            Browser::inApp($next->external_url);

            return;
        }

        $this->replace(self::uriFor($this->tab, $next->id));
    }

    /** A licensed chapter reached directly (Continue on an external chapter): the publisher's page. */
    public function openExternal(): void
    {
        $url = $this->chapter()?->external_url;

        if ($url !== null) {
            Browser::inApp($url);
        }
    }

    /** Offline: ask again every `offline_retry_seconds` unless the phone says it has no connection. */
    #[Poll(1000)]
    public function checkPages(): void
    {
        $fetcher = app(PageFetcher::class);

        if (! $fetcher->isRunning($this->chapterId) && $this->restartIfShorter()) {
            $this->askForPages();

            return;
        }

        if ($fetcher->state($this->chapterId) !== LoadState::Offline || $fetcher->isRunning($this->chapterId)) {
            return;
        }

        if (Carbon::now()->getTimestampMs() - $this->askedAt < config('readex.reader.offline_retry_seconds') * 1000) {
            return;
        }

        if (Network::status()?->connected === false) {
            $this->askedAt = Carbon::now()->getTimestampMs();

            return;
        }

        $this->askForPages();
    }

    public function unmount(): void
    {
        $this->forget();
        $this->saveProgress();
        $this->markOpened();

        parent::unmount();
    }

    public function render(): View
    {
        $this->forget();
        $chapter = $this->chapter();
        $screen = $this->screen($chapter);

        if ($screen !== 'pages') {
            return view('native.reader', ['screen' => $screen, 'chapter' => $chapter, 'host' => $chapter === null ? null : parse_url((string) $chapter->external_url, PHP_URL_HOST)]);
        }

        $pages = $this->pages();
        $ratio = self::typicalRatio($pages);

        // virtual_list item views only get $index.
        Views::share('readerRows', $this->windowRows($chapter, $pages, $ratio));

        return view('native.reader', [
            'screen' => $screen,
            'rows' => $this->rowCount(),
            'from' => $this->virtualWindowFrom,
            'to' => $this->virtualWindowTo,
            'rowHeight' => (int) round(config('readex.reader.width') * $ratio[1] / $ratio[0]),
        ]);
    }

    /**
     * missing / external / unavailable / offline (never stored, no connection) / loading (no
     * page count yet) / pages.
     */
    private function screen(?Chapter $chapter): string
    {
        if ($chapter === null) {
            return 'missing';
        }

        if ($chapter->external_url !== null) {
            return 'external';
        }

        $fetcher = app(PageFetcher::class);
        $stored = app(PageStore::class)->manifest($this->chapterId) !== null;
        $idle = ! $fetcher->isRunning($this->chapterId);

        return match (true) {
            ! $stored && $idle && $fetcher->state($this->chapterId) === LoadState::Unavailable => 'unavailable',
            ! $stored && $idle && $fetcher->state($this->chapterId) === LoadState::Offline => 'offline',
            $this->pageCount() === 0 => 'loading',
            default => 'pages',
        };
    }

    /**
     * The rows inside the window, keyed by row index.
     *
     * @param  list<array{src: string, width: int, height: int}|null>  $pages
     * @param  array{int, int}  $ratio
     * @return array<int, array<string, mixed>>
     */
    private function windowRows(Chapter $chapter, array $pages, array $ratio): array
    {
        $fetcher = app(PageFetcher::class);
        $failed = $fetcher->failed($this->chapterId);
        $waiting = $fetcher->state($this->chapterId) === LoadState::Offline && ! $fetcher->isRunning($this->chapterId);
        $count = $this->pageCount();
        $label = MangaDetail::chapterLabel($chapter);
        $credit = 'Scanlation: '.($chapter->group_name ?? 'No group');
        $rows = [];

        for ($row = $this->virtualWindowFrom; $row <= min($this->virtualWindowTo, $this->rowCount() - 1); $row++) {
            $index = $this->pageAt($row);

            $rows[$row] = match (true) {
                $row === 0 => [
                    'kind' => 'header',
                    'label' => implode(' · ', array_filter([$label, $chapter->title])),
                    'credit' => $credit,
                    'before' => $this->firstPage() > 0 ? 'Pages 1–'.$this->firstPage() : null,
                ],
                $index === null => [
                    'kind' => 'end',
                    'label' => "End of {$label}",
                    'credit' => $credit,
                    'next' => $this->nextLabel($chapter),
                ],
                default => [
                    'kind' => 'page',
                    'number' => $index + 1,
                    'index' => $index,
                    'src' => $pages[$index]['src'] ?? null,
                    'ratio' => isset($pages[$index]) ? "{$pages[$index]['width']}/{$pages[$index]['height']}" : "{$ratio[0]}/{$ratio[1]}",
                    'failed' => in_array($index, $failed, true),
                    'waiting' => $waiting,
                ],
            };
        }

        return $rows;
    }

    private function nextLabel(Chapter $chapter): ?string
    {
        $next = app(LibraryRepository::class)->after($chapter);

        return $next === null ? null : 'Next: '.MangaDetail::chapterLabel($next);
    }

    /** Pages in the window (reading order) plus a few after it. */
    private function askForPages(): void
    {
        $count = $this->pageCount();

        if ($this->chapter()?->external_url !== null) {
            return;
        }

        $wanted = [];

        for ($row = $this->virtualWindowFrom; $row <= $this->virtualWindowTo; $row++) {
            $index = $this->pageAt($row);

            if ($index !== null) {
                $wanted[] = $index;
            }
        }

        $last = $wanted === [] ? $this->start - 1 : max($wanted);

        for ($i = 1; $i <= config('readex.reader.lookahead'); $i++) {
            if ($count === 0 || $last + $i < $count) {
                $wanted[] = $last + $i;
            }
        }

        $this->askedAt = Carbon::now()->getTimestampMs();
        app(PageFetcher::class)->want($this->chapterId, $wanted);

        if ($this->restartIfShorter()) {
            $this->askForPages();
        }
    }

    /**
     * The feed's page count can be wrong; once @Home answered with fewer pages than the saved
     * page, the reader starts at page 1 and asks for those pages instead.
     */
    private function restartIfShorter(): bool
    {
        $this->forget();

        if ($this->start === 0 || app(PageStore::class)->manifest($this->chapterId) === null || $this->start < $this->pageCount()) {
            return false;
        }

        $this->start = 0;
        $this->page = 0;
        $this->setVirtualWindow(0, self::FIRST_WINDOW);

        return true;
    }

    /** Only once the chapter's page list is known: a chapter never loaded keeps its progress. */
    private function saveProgress(): void
    {
        $count = $this->pageCount();

        if ($count === 0 || app(PageStore::class)->manifest($this->chapterId) === null) {
            return;
        }

        app(LibraryRepository::class)->saveProgress($this->chapterId, min($this->page, $count - 1), $count);
    }

    private function markOpened(): void
    {
        StoredChapter::query()->whereKey($this->chapterId)->update(['last_opened_at' => Carbon::now()]);
    }

    private function pageFromWindow(): int
    {
        $rows = $this->rowCount();

        if ($rows === 0) {
            return $this->page;
        }

        if ($this->virtualWindowTo >= $rows - 1) {
            return $this->pageCount() - 1;
        }

        return $this->pageAt($this->virtualWindowFrom === 0 ? 1 : $this->virtualWindowFrom + self::OVERSCAN) ?? $this->page;
    }

    /** 0-based page shown by list row `$row`; null for the header and end rows. */
    private function pageAt(int $row): ?int
    {
        $count = $this->pageCount();

        if ($row <= 0 || $row >= $this->rowCount() - 1) {
            return null;
        }

        return min($this->firstPage() + $row - 1, $count - 1);
    }

    private function rowCount(): int
    {
        $count = $this->pageCount();

        return $count === 0 ? 0 : 1 + ($count - $this->firstPage()) + 1;
    }

    /** `$start`, unless the chapter turned out shorter than the saved page. */
    private function firstPage(): int
    {
        return $this->start < $this->pageCount() ? $this->start : 0;
    }

    /** From the stored page list once the @Home server answered, else the feed's count. */
    private function pageCount(): int
    {
        $pages = $this->pages();

        return $pages !== [] ? count($pages) : (int) ($this->chapter()?->pages ?? 0);
    }

    /**
     * PageStore::pages() reads the manifest and checks every file; pages land while the screen is
     * open, so this is kept for one action or render only.
     *
     * @return list<array{src: string, width: int, height: int}|null>
     */
    private function pages(): array
    {
        return $this->seen['pages'] ??= app(PageStore::class)->pages($this->chapterId);
    }

    private function chapter(): ?Chapter
    {
        return $this->seen['chapter'] ??= Chapter::query()->find($this->chapterId);
    }

    private function forget(): void
    {
        $this->seen = [];
    }

    /**
     * Width / height of the chapter's middle page by shape (stored pages), so placeholders and
     * rows outside the window are about as tall as the pages; config ratio before any page is stored.
     *
     * @param  list<array{src: string, width: int, height: int}|null>  $pages
     * @return array{int, int}
     */
    public static function typicalRatio(array $pages): array
    {
        $known = array_values(array_filter($pages));

        if ($known === []) {
            return config('readex.reader.page_ratio');
        }

        usort($known, fn (array $a, array $b) => $a['height'] / $a['width'] <=> $b['height'] / $b['width']);
        $middle = $known[intdiv(count($known), 2)];

        return [$middle['width'], $middle['height']];
    }
}
