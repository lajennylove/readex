<?php

namespace App\NativeComponents\Concerns;

use App\Models\Chapter;
use App\NativeComponents\MangaDetail;
use App\NativeComponents\Reader;
use App\NativeLayouts\TabsLayout;
use Native\Mobile\Facades\Browser;

/** Where a chapter opens: the reader inside the current tab, from outside Detail on top of its series' Detail; licensed chapters that the source doesn't serve pages for (the publisher hosts them itself) open in the in-app browser. */
trait OpensChapters
{
    /** @return bool the reader is opening (false: the publisher's page is) */
    protected function showChapter(Chapter $chapter, bool $replace = false): bool
    {
        if ($chapter->external_url !== null) {
            Browser::inApp($chapter->external_url);

            return false;
        }

        $uri = Reader::uriFor(TabsLayout::tabOf($this->nativeRouter?->currentUri()), $chapter->id);

        $replace ? $this->replace($uri) : $this->navigate($uri);

        return true;
    }

    /** The series' Detail in the current tab, which opens the chapter on top of itself (MangaDetail::READ), so back goes Reader → Detail. */
    protected function showChapterViaDetail(Chapter $chapter): void
    {
        $this->navigate(
            TabsLayout::mangaUri(TabsLayout::tabOf($this->nativeRouter?->currentUri()), $chapter->manga_id),
            [MangaDetail::READ => $chapter->id],
        );
    }
}
