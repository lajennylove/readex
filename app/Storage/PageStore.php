<?php

namespace App\Storage;

use App\Models\Chapter;
use App\Models\StoredChapter;
use App\Storage\Exceptions\DiskSpaceLow;
use ErrorException;
use FilesystemIterator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Where a chapter's pages live (spec §5.4, §11.1). One folder per chapter with `001.jpg`, … and
 * `manifest.json` (chapter details, quality, each page's file and measured size; the source sends no
 * page sizes). The `stored_chapters` row keeps tier, quality and counts; the files on disk are the
 * truth: iOS may purge the cache tier, and a missing file only means "fetch it again".
 */
final class PageStore
{
    /** `icloud` comes with Phase 7. */
    public const TIERS = ['cache', 'local'];

    private const MANIFEST_VERSION = 1;

    /** A move copies the folder when rename() fails, so the lock outlives a slow copy. */
    private const LOCK_SECONDS = 60;

    private const LOCK_WAIT_SECONDS = 10;

    public function __construct(
        private readonly StorageBridge $bridge,
        private readonly DiskSpaceGuard $diskSpace,
    ) {}

    /**
     * Start storing a chapter, or pick up where it stopped. A chapter keeps the quality, tier and
     * page count it started with (§12.1); a purged folder gets a fresh manifest. A chapter the
     * image cache dropped isn't begun in the cache tier again until forgetEvicted().
     *
     * @throws ChapterGone
     */
    public function begin(Chapter $chapter, string $quality, int $pageCount, string $tier = 'cache'): StoredChapter
    {
        if (! in_array($quality, config('readex.image_qualities'), true)) {
            throw new InvalidArgumentException("Unknown image quality [{$quality}].");
        }

        if (! in_array($tier, self::TIERS, true)) {
            throw new InvalidArgumentException("Unknown storage tier [{$tier}].");
        }

        if (! $this->isSafe($chapter->id)) {
            throw new InvalidArgumentException("Chapter id [{$chapter->id}] is not a folder name.");
        }

        return $this->locked($chapter->id, function () use ($chapter, $quality, $pageCount, $tier) {
            if ($tier === 'cache' && $this->wasEvicted($chapter->id)) {
                throw new ChapterGone("Chapter [{$chapter->id}] was dropped from the image cache.");
            }

            $stored = StoredChapter::query()->firstOrCreate(['chapter_id' => $chapter->id], [
                'tier' => $tier,
                'quality' => $quality,
                'status' => 'partial',
                'page_count' => $pageCount,
            ]);

            if ($stored->wasRecentlyCreated && $stored->tier === 'local') {
                $this->excludeDownloads();
            }

            $this->write($stored, $this->read($stored) ?? $this->newManifest($chapter, $stored));

            return $stored;
        });
    }

    private function newManifest(Chapter $chapter, StoredChapter $stored): array
    {
        return [
            'version' => self::MANIFEST_VERSION,
            'manga_id' => $chapter->manga_id,
            'chapter_id' => $chapter->id,
            'number' => $chapter->number,
            'volume' => $chapter->volume,
            'title' => $chapter->title,
            'group' => $chapter->group_name,
            'language' => $chapter->language,
            'quality' => $stored->quality,
            'pages' => array_fill(0, $stored->page_count, null),
        ];
    }

    /**
     * Store page `$index` (0-based). False when it isn't an image or is out of range. The folder is
     * looked up under the chapter's lock, so a page never lands in the tier a promote() / demote()
     * just moved the chapter out of.
     *
     * @throws ChapterGone the chapter wasn't begun, or was dropped meanwhile
     * @throws DiskSpaceLow the app's storage volume is below the configured minimum free space
     */
    public function put(string $chapterId, int $index, string $bytes): bool
    {
        if ($this->stored($chapterId) === null) {
            throw new ChapterGone("Chapter [{$chapterId}] is not stored.");
        }

        $size = @getimagesizefromstring($bytes);
        $extension = $size === false ? false : image_type_to_extension($size[2], false);

        if ($extension === false) {
            return false;
        }

        $this->diskSpace->ensure();

        return $this->locked($chapterId, function () use ($chapterId, $index, $bytes, $size, $extension) {
            $stored = $this->stored($chapterId);
            $manifest = $stored === null ? null : $this->read($stored);

            if ($manifest === null) {
                throw new ChapterGone("Chapter [{$chapterId}] is not stored.");
            }

            if ($index < 0 || $index >= count($manifest['pages'])) {
                return false;
            }

            $folder = $this->folder($stored);
            $name = sprintf('%03d', $index + 1);
            $file = $name.'.'.($extension === 'jpeg' ? 'jpg' : $extension);

            foreach (glob("{$folder}/{$name}.*") ?: [] as $old) {
                @unlink($old);
            }

            $this->atomic("{$folder}/{$file}", $bytes);

            $manifest['pages'][$index] = ['file' => $file, 'width' => $size[0], 'height' => $size[1], 'bytes' => strlen($bytes)];
            $this->write($stored, $manifest);

            return true;
        });
    }

    /**
     * Download (D34): move the chapter to the local tier, which iOS never purges and the cache
     * cap never touches. Quality, counts and manifest stay. False when it isn't stored.
     *
     * @throws DiskSpaceLow the app's storage volume is below the configured minimum free space
     */
    public function promote(string $chapterId): bool
    {
        return $this->move($chapterId, 'local');
    }

    /**
     * Remove download (D35 11): back to the cache tier, still readable until the cache needs the space.
     *
     * @throws DiskSpaceLow the app's storage volume is below the configured minimum free space
     */
    public function demote(string $chapterId): bool
    {
        return $this->move($chapterId, 'cache');
    }

    private function move(string $chapterId, string $tier): bool
    {
        if ($this->stored($chapterId) === null) {
            return false;
        }

        $this->diskSpace->ensure();

        return $this->locked($chapterId, function () use ($chapterId, $tier) {
            $stored = $this->stored($chapterId);

            if ($stored === null) {
                return false;
            }

            if ($stored->tier === $tier) {
                return true;
            }

            $from = $this->folder($stored);
            $stored->tier = $tier;
            $to = $this->folder($stored);

            if (is_dir($from)) {
                File::deleteDirectory($to);
                File::ensureDirectoryExists(dirname($to));

                if (! @rename($from, $to)) {
                    if (! $this->copy($from, $to)) {
                        File::deleteDirectory($to);

                        return false;
                    }

                    File::deleteDirectory($from);
                }
            }

            $stored->save();

            if ($tier === 'local') {
                $this->excludeDownloads();
            }

            return true;
        });
    }

    /** False when the folder couldn't be copied whole (no space, no permission). */
    private function copy(string $from, string $to): bool
    {
        try {
            return File::copyDirectory($from, $to);
        } catch (ErrorException) {
            return false;
        }
    }

    /** Spec §11.1: downloads are not in the iCloud backup (they can be downloaded again). */
    private function excludeDownloads(): void
    {
        File::ensureDirectoryExists(self::downloadsPath());
        $this->bridge->excludeFromBackup(self::downloadsPath());
    }

    /**
     * Page writes and folder moves of one chapter, one at a time across the UI, the queue worker
     * and the async lane (the cache store is the shared database).
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function locked(string $chapterId, callable $work): mixed
    {
        return Cache::lock("readex.pages.store.{$chapterId}", self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, $work);
    }

    /** Every page is on the phone. */
    public function has(string $chapterId): bool
    {
        $pages = $this->pages($chapterId);

        return $pages !== [] && ! in_array(null, $pages, true);
    }

    public function hasPage(string $chapterId, int $index): bool
    {
        return ($this->pages($chapterId)[$index] ?? null) !== null;
    }

    /** Image src for page `$index`, or null while it isn't on the phone. */
    public function pathFor(string $chapterId, int $index): ?string
    {
        return $this->pages($chapterId)[$index]['src'] ?? null;
    }

    /**
     * One entry per page in reading order, null while the page isn't on the phone; [] when the
     * chapter isn't stored.
     *
     * @return list<array{src: string, width: int, height: int}|null>
     */
    public function pages(string $chapterId): array
    {
        $stored = $this->stored($chapterId);
        $manifest = $stored === null ? null : $this->read($stored);

        if ($manifest === null) {
            return [];
        }

        $folder = $this->folder($stored);
        $prefix = $this->src($stored);

        return array_map(fn (?array $page) => $page !== null && is_file("{$folder}/{$page['file']}")
            ? ['src' => "{$prefix}/{$page['file']}", 'width' => $page['width'], 'height' => $page['height']]
            : null, $manifest['pages']);
    }

    /** @return list<int> 0-based indexes of the pages not on the phone, in reading order */
    public function missing(string $chapterId): array
    {
        return array_keys(array_filter($this->pages($chapterId), fn (?array $page) => $page === null));
    }

    public function manifest(string $chapterId): ?array
    {
        $stored = $this->stored($chapterId);

        return $stored === null ? null : $this->read($stored);
    }

    /**
     * Delete a cache chapter's folder and its stored_chapters row. A download is never evicted:
     * the image cache works from a list read before another runtime may have promoted the chapter.
     * The chapter stays out of the cache tier for `evicted_seconds` unless forgetEvicted().
     */
    public function evict(string $chapterId): bool
    {
        if ($this->stored($chapterId) === null) {
            return false;
        }

        return $this->locked($chapterId, function () use ($chapterId) {
            $stored = $this->stored($chapterId);

            if ($stored === null || $stored->tier !== 'cache') {
                return false;
            }

            File::deleteDirectory($this->folder($stored));
            $stored->delete();
            Cache::put($this->evictedKey($chapterId), true, config('readex.pages.evicted_seconds'));

            return true;
        });
    }

    /** Dropped by Clear image cache or the cap lately: background cache jobs leave it alone. */
    public function wasEvicted(string $chapterId): bool
    {
        return Cache::has($this->evictedKey($chapterId));
    }

    /** The reader asks for the chapter again: it may be begun in the cache tier again. */
    public function forgetEvicted(string $chapterId): void
    {
        Cache::forget($this->evictedKey($chapterId));
    }

    private function evictedKey(string $chapterId): string
    {
        return "readex.pages.evicted.{$chapterId}";
    }

    /** Bytes on disk in the cache tier: chapter folders and cover thumbnails. */
    public function usage(): int
    {
        $bytes = 0;

        foreach (['chapters', 'covers'] as $folder) {
            $path = $this->cachesPath()."/{$folder}";

            if (! is_dir($path)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
                $bytes += $file->isFile() ? $file->getSize() : 0;
            }
        }

        return $bytes;
    }

    private function stored(string $chapterId): ?StoredChapter
    {
        return $this->isSafe($chapterId) ? StoredChapter::query()->find($chapterId) : null;
    }

    private function read(StoredChapter $stored): ?array
    {
        $path = $this->folder($stored).'/manifest.json';
        $manifest = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($manifest) && is_array($manifest['pages'] ?? null) ? $manifest : null;
    }

    /** Save the manifest and bring the row's counts in line with it. */
    private function write(StoredChapter $stored, array $manifest): void
    {
        $this->atomic($this->folder($stored).'/manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $done = array_filter($manifest['pages']);
        $isDone = count($done) === count($manifest['pages']);

        $stored->update([
            'pages_done' => count($done),
            'bytes' => array_sum(array_column($done, 'bytes')),
            'status' => $isDone ? 'done' : 'partial',
            // Library "Recently downloaded" sort (spec §1): the moment a chapter first turns
            // done, not touched again by a later write (e.g. Remove download + re-download keeps
            // its own new timestamp, but a chapter already done never updates this twice).
            'downloaded_at' => $isDone ? ($stored->downloaded_at ?? Carbon::now()) : $stored->downloaded_at,
        ]);
    }

    private function atomic(string $path, string $contents): void
    {
        File::ensureDirectoryExists(dirname($path));
        $part = $path.'.'.bin2hex(random_bytes(4)).'.part';

        try {
            file_put_contents($part, $contents);
            rename($part, $path);
        } finally {
            if (is_file($part)) {
                @unlink($part);
            }
        }
    }

    private function folder(StoredChapter $stored): string
    {
        return match ($stored->tier) {
            'local' => self::downloadsPath()."/{$stored->chapter_id}",
            default => $this->cachesPath()."/chapters/{$stored->chapter_id}",
        };
    }

    /** Folder as the screens see it, through the `storage` (and `storage/cache`) links. */
    private function src(StoredChapter $stored): string
    {
        return match ($stored->tier) {
            'local' => "storage/downloads/{$stored->chapter_id}",
            default => "storage/cache/chapters/{$stored->chapter_id}",
        };
    }

    private static function downloadsPath(): string
    {
        return storage_path('app/public/downloads');
    }

    private function cachesPath(): string
    {
        return rtrim($this->bridge->cachesPath(), '/');
    }

    /** Source chapter ids are UUIDs; anything else could leave the chapters folder. */
    private function isSafe(string $chapterId): bool
    {
        return preg_match('/^[A-Za-z0-9-]+$/', $chapterId) === 1;
    }
}
