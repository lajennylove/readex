<?php

namespace App\Storage;

use App\Covers\CoverStore;
use App\Models\StoredChapter;
use Illuminate\Support\Collection;

/**
 * The cache tier's size policy (spec §11.1): trimmed to the cap, oldest-read first, or cleared
 * on request. Never the open chapter, never downloads.
 */
final class ImageCache
{
    public function __construct(
        private readonly PageStore $pages,
        private readonly CoverStore $covers,
        private readonly OpenChapter $open,
    ) {}

    /** Bytes of cache-tier chapters and covers on disk. */
    public function usage(): int
    {
        return $this->pages->usage();
    }

    /** Delete cache chapters, oldest-read first (never-opened last), until the cache fits. Covers count but stay. */
    public function enforce(): void
    {
        $cap = config('readex.cache.cap_bytes');

        foreach ($this->cached() as $chapterId) {
            if ($this->usage() <= $cap) {
                return;
            }

            $this->evictUnlessOpen($chapterId);
        }
    }

    /** Cache chapters and cover thumbnails; reading progress, the Library and downloads stay. */
    public function clear(): void
    {
        foreach ($this->cached() as $chapterId) {
            $this->evictUnlessOpen($chapterId);
        }

        $this->covers->clear();
    }

    /** @return Collection<int, string> cache-tier chapter ids, last opened first, never opened last */
    private function cached(): Collection
    {
        return StoredChapter::query()->where('tier', 'cache')
            ->orderByRaw('last_opened_at is null')->orderBy('last_opened_at')->orderBy('chapter_id')
            ->pluck('chapter_id');
    }

    private function evictUnlessOpen(string $chapterId): void
    {
        if ($chapterId !== $this->open->id()) {
            $this->pages->evict($chapterId);
        }
    }
}
