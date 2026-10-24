<?php

namespace App\NativeComponents\Concerns;

use App\Covers\CoverStore;
use App\NativeComponents\MangaDetail;
use App\NativeComponents\Reader;
use App\NativeLayouts\TabsLayout;
use App\Repositories\ContinueReading;
use App\Repositories\LibraryRepository;
use App\Repositories\SettingsRepository;
use Native\Mobile\Edge\Element;

/**
 * D31, D33 choice 8: the resume bar TabsLayout pins above the tab bar (HasResumeBar). Its tap
 * resolves on the screen that rendered it, so every screen showing the bar uses this trait.
 */
trait ShowsResumeBar
{
    use OpensChapters;

    /** Null when nothing was read or the series read last is caught up. Built with every frame: SQLite and the cover path only. */
    public function resumeBar(): ?Element
    {
        $last = app(LibraryRepository::class)->lastOpened();

        return $last === null ? null : $this->fromViewPartial(view('native.partials.resume-bar', self::resumeBarData($last)));
    }

    /** Opens what the bar shows now: via the series' Detail, straight on top of it when that's where the router is. */
    public function openResumeBar(): void
    {
        $chapter = app(LibraryRepository::class)->lastOpened()?->chapter;

        if ($chapter === null) {
            return;
        }

        $uri = $this->nativeRouter?->currentUri();
        $tab = TabsLayout::tabOf($uri);

        if ($uri === Reader::uriFor($tab, $chapter->id)) {
            return;
        }

        if ($uri === TabsLayout::mangaUri($tab, $chapter->manga_id)) {
            $this->showChapter($chapter);
        } else {
            $this->showChapterViaDetail($chapter);
        }
    }

    /**
     * "Ch. 12 · page 5 / 43" while reading, "Ch. 13 · Up next" for the next, unopened chapter,
     * "Ch. 12" while its page count isn't known.
     *
     * @return array{title: string, line: string, a11y: string, cover: ?string, value: ?float}
     */
    private static function resumeBarData(ContinueReading $last): array
    {
        $title = app(SettingsRepository::class)->chain()->title($last->manga->title ?? [], $last->manga->alt_titles ?? []);
        $label = MangaDetail::chapterLabel($last->chapter);
        $progress = $last->progress;

        [$line, $spoken, $value] = match (true) {
            $progress === null => ["{$label} · Up next", "{$label}, up next", null],
            $progress->page_count === 0 => [$label, $label, null],
            default => [
                "{$label} · page ".($progress->page + 1)." / {$progress->page_count}",
                "{$label}, page ".($progress->page + 1)." of {$progress->page_count}",
                ($progress->page + 1) / $progress->page_count,
            ],
        };

        return [
            'title' => $title,
            'line' => $line,
            'a11y' => "Resume {$title}, {$spoken}",
            'cover' => app(CoverStore::class)->path($last->manga),
            'value' => $value,
        ];
    }
}
