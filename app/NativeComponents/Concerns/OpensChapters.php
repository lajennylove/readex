<?php

namespace App\NativeComponents\Concerns;

use App\Models\Chapter;
use App\NativeComponents\Reader;
use App\NativeLayouts\TabsLayout;
use Native\Mobile\Facades\Browser;

/** Where a chapter opens: the reader inside the current tab; licensed chapters (MangaPlus, VIZ…) only exist on the publisher's site, so the in-app browser. */
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
}
