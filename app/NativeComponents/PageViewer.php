<?php

namespace App\NativeComponents;

use App\Models\Chapter;
use App\NativeLayouts\TabsLayout;
use App\Storage\PageStore;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\SharedValue;

/**
 * D33 choice 2: one stored page full screen, pushed from a double-tap in the Reader (which stays
 * on the stack underneath, so back returns to the same spot). Pinch to zoom, pan while zoomed,
 * double-tap 1× / 2.5×. Opens with the bar, a tap hides / shows it. Only pages on the phone: a page
 * that isn't says so, never fetched here.
 */
class PageViewer extends NativeComponent
{
    public const MAX_ZOOM = 4.0;

    public const DOUBLE_TAP_ZOOM = 2.5;

    public string $chapterId = '';

    public string $tab = 'home';

    /** Page shown (1-based). */
    public int $number = 1;

    public bool $barShown = true;

    public float $zoom = 1.0;

    public float $x = 0.0;

    public float $y = 0.0;

    /**
     * Kept for the life of this viewer: a SharedValue made per render gets a new id, which iOS
     * seeds afresh, so the zoom snapped back on release.
     *
     * @var array<string, SharedValue>
     */
    private array $values = [];

    public static function uriFor(string $tab, string $chapterId, int $n): string
    {
        return '/'.(in_array($tab, TabsLayout::DETAIL_TABS, true) ? $tab : 'home')."/read/{$chapterId}/page/{$n}";
    }

    public function mount(string $chapterId, string $n): void
    {
        $this->chapterId = $chapterId;
        $this->number = (int) $n;
        $this->tab = TabsLayout::tabOf($this->nativeRouter?->currentUri());
    }

    /** Never empty: iOS tabs drop the NavigationStack for an untitled screen. */
    public function navTitle(): string
    {
        $chapter = Chapter::query()->find($this->chapterId);

        if ($chapter === null) {
            return "Page {$this->number}";
        }

        $pages = app(PageStore::class)->pages($this->chapterId);
        $count = $pages !== [] ? count($pages) : (int) $chapter->pages;

        return MangaDetail::chapterLabel($chapter).($this->number >= 1 && $this->number <= $count ? " · {$this->number} / {$count}" : '');
    }

    /** Opens shown, a tap hides it; always shown for a page that isn't on the phone, so back is there. */
    public function shouldHideNavBar(): bool
    {
        return ! $this->barShown && $this->page() !== null;
    }

    public function toggleBar(): void
    {
        $this->barShown = ! $this->barShown;
    }

    public function pinchEnded(float $scale): void
    {
        $this->zoom = max(1.0, min(self::MAX_ZOOM, $scale));
        $this->clampPan();
    }

    public function dragEnded(float $x, float $y): void
    {
        $this->x = $x;
        $this->y = $y;
        $this->clampPan();
    }

    public function doubleTapped(): void
    {
        $this->zoom = $this->zoomed() ? 1.0 : self::DOUBLE_TAP_ZOOM;
        $this->clampPan();
    }

    public function render(): View
    {
        $page = $this->page();

        if ($page === null) {
            return view('native.page-viewer', ['page' => null, 'number' => $this->number]);
        }

        [$boundX, $boundY] = self::bounds($this->zoom, $page);

        return view('native.page-viewer', [
            'page' => $page,
            'number' => $this->number,
            'zoomed' => $this->zoomed(),
            'scale' => $this->value('scale', $this->zoom),
            'panX' => $this->value('x', $this->x),
            'panY' => $this->value('y', $this->y),
            'boundX' => $boundX,
            'boundY' => $boundY,
        ]);
    }

    /**
     * How far the page may move from centre at `$zoom`: the page is drawn at the reader's width,
     * the viewer is `viewer_height` tall.
     *
     * @param  array{src: string, width: int, height: int}  $page
     * @return array{float, float}
     */
    public static function bounds(float $zoom, array $page): array
    {
        $width = config('readex.reader.width');
        $height = $width * $page['height'] / $page['width'];

        return [
            max(0.0, ($zoom * $width - $width) / 2),
            max(0.0, ($zoom * $height - config('readex.reader.viewer_height')) / 2),
        ];
    }

    /** PHP's value is pushed on every render; iOS only applies it when it changed. */
    private function value(string $key, float $current): SharedValue
    {
        $value = $this->values[$key] ??= SharedValue::make($current);
        $value->setValue($current);

        return $value;
    }

    private function zoomed(): bool
    {
        return $this->zoom > 1.01;
    }

    /** Unzoomed = centred; zoomed = inside the current zoom's bounds. */
    private function clampPan(): void
    {
        $page = $this->page();
        [$boundX, $boundY] = $page === null || ! $this->zoomed() ? [0.0, 0.0] : self::bounds($this->zoom, $page);

        $this->x = max(-$boundX, min($boundX, $this->x));
        $this->y = max(-$boundY, min($boundY, $this->y));
    }

    /** @return array{src: string, width: int, height: int}|null the stored page, null while it isn't on the phone */
    private function page(): ?array
    {
        return app(PageStore::class)->pages($this->chapterId)[$this->number - 1] ?? null;
    }
}
