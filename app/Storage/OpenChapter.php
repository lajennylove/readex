<?php

namespace App\Storage;

use Illuminate\Support\Facades\Cache;

/**
 * The chapter on the reader's screen, for the cache cap and Clear image cache. In the shared
 * cache store because they also run in the phone's queue worker.
 */
final class OpenChapter
{
    private const KEY = 'readex.reader.open';

    public function set(string $chapterId): void
    {
        Cache::forever(self::KEY, $chapterId);
    }

    /** Only if `$chapterId` is still the open one: a reader that was replaced doesn't clear its successor. */
    public function clear(string $chapterId): void
    {
        if ($this->id() === $chapterId) {
            Cache::forget(self::KEY);
        }
    }

    public function id(): ?string
    {
        return Cache::get(self::KEY);
    }
}
