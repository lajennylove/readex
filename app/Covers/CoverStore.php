<?php

namespace App\Covers;

use App\Adapters\Http\Client;
use App\Adapters\Http\Exceptions\ApiError;
use App\Adapters\Http\Exceptions\RequestDeferred;
use App\Adapters\Http\RateLimit\Priority;
use App\Jobs\DownloadCovers;
use App\Models\Manga;
use App\Storage\DiskSpaceGuard;
use App\Storage\Exceptions\DiskSpaceLow;
use App\Storage\StorageBridge;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Native\Mobile\PendingAsyncTask;

/**
 * Cover thumbnails in the cache tier (spec §11.1): `<caches>/covers/<manga id>/<file>.256.jpg`,
 * shown through the `storage/cache` link. iOS may purge the folder, so a missing file only
 * means "download it again".
 */
final class CoverStore
{
    public function __construct(
        private readonly Client $client,
        private readonly StorageBridge $bridge,
        private readonly DiskSpaceGuard $diskSpace,
    ) {}

    /** Image src for a tile, or null while the thumbnail isn't on the phone (grey placeholder). */
    public function path(Manga $manga): ?string
    {
        $relative = $this->relative($manga);

        return $relative !== null && is_file($this->absolute($relative)) ? 'storage/cache/'.$relative : null;
    }

    /**
     * Queue a DownloadCovers job for the covers that are missing and not queued in the last
     * `queued_seconds`.
     *
     * @param  iterable<Manga>  $mangas
     */
    public function queue(iterable $mangas, Priority $priority): void
    {
        $ids = $this->missing($mangas, 'queued');

        if ($ids !== []) {
            DownloadCovers::dispatch($ids, $priority);
        }
    }

    /**
     * Start a FetchCovers task for the covers on screen that are missing and not fetched in the
     * last `queued_seconds`. Its own guard, so covers a queued job holds are still fetched now.
     *
     * @param  iterable<Manga>  $mangas
     */
    public function fetch(iterable $mangas): ?PendingAsyncTask
    {
        $ids = $this->missing($mangas, 'fetched');

        Log::channel('covers')->debug('CoverStore::fetch', ['dispatching' => count($ids), 'ids' => $ids]);

        return $ids === [] ? null : FetchCovers::dispatch($ids);
    }

    /**
     * Download one thumbnail (covers bucket). False when the series has no cover or the
     * response isn't an image; the series' older cover files are removed on success.
     *
     * @throws RequestDeferred|ApiError|ConnectionException|DiskSpaceLow
     */
    public function download(Manga $manga, Priority $priority, float $maxWait = 10.0): bool
    {
        $relative = $this->relative($manga);

        if ($relative === null) {
            Log::channel('covers')->warning('download: relative=null (missing cover_file)', [
                'manga' => $manga->id, 'cover_file' => $manga->cover_file,
            ]);

            return false;
        }

        $path = $this->absolute($relative);

        if (is_file($path)) {
            Log::channel('covers')->debug('download: cache hit', ['manga' => $manga->id]);

            return true;
        }

        $this->diskSpace->ensure();

        $url = $this->url($manga);
        Log::channel('covers')->info('download: cache miss, GET', [
            'manga' => $manga->id, 'url' => $url, 'priority' => $priority->value,
        ]);

        $bytes = $this->client->download($url, $priority, $maxWait);

        if (@getimagesizefromstring($bytes) === false) {
            Log::channel('covers')->warning('download: response is not an image', [
                'manga' => $manga->id, 'bytes' => strlen($bytes),
            ]);

            return false;
        }

        File::ensureDirectoryExists(dirname($path));
        $part = $path.'.'.bin2hex(random_bytes(4)).'.part';
        file_put_contents($part, $bytes);
        rename($part, $path);

        foreach (glob(dirname($path).'/*.jpg') ?: [] as $old) {
            if ($old !== $path) {
                @unlink($old);
            }
        }

        Log::channel('covers')->info('download: ok', [
            'manga' => $manga->id, 'bytes' => strlen($bytes), 'path' => $relative,
        ]);

        return true;
    }

    /** Delete every thumbnail; a tile that shows its series downloads it again. */
    public function clear(): void
    {
        File::deleteDirectory($this->absolute('covers'));
    }

    public function url(Manga $manga): string
    {
        $size = config('readex.covers.size');

        return rtrim(config('source.covers_url'), '/')."/{$manga->id}/{$manga->cover_file}.{$size}.jpg";
    }

    /**
     * `$mangas` filtered to those whose cover isn't on disk yet, in order. Read-only (no
     * `queued_seconds` guard claimed), unlike `fetch()`/`queue()` — for callers that need to
     * size a budget against actual need (e.g. `ShowsTiles::queueRowCovers()`) without spending
     * one of the guard's single uses.
     *
     * @param  iterable<Manga>  $mangas
     * @return list<Manga>
     */
    public function missingCovers(iterable $mangas): array
    {
        $missing = [];
        $skipped = [];
        $cached = [];

        foreach ($mangas as $manga) {
            $relative = $this->relative($manga);

            if ($relative === null) {
                $skipped[] = ['id' => $manga->id, 'cover_file' => $manga->cover_file];

                continue;
            }

            if (! is_file($this->absolute($relative))) {
                $missing[] = $manga;
            } else {
                $cached[] = $manga->id;
            }
        }

        Log::channel('covers')->debug('missingCovers', [
            'input' => is_countable($mangas) ? count($mangas) : null,
            'cached' => count($cached),
            'missing' => count($missing),
            'skipped_null_relative' => count($skipped),
            'skipped_samples' => array_slice($skipped, 0, 3),
        ]);

        return $missing;
    }

    /**
     * Ids in order whose cover isn't on disk, each claimed under `$guard` for `queued_seconds`
     * (screens re-render every few seconds).
     *
     * @param  iterable<Manga>  $mangas
     * @return list<string>
     */
    private function missing(iterable $mangas, string $guard): array
    {
        $ids = [];

        foreach ($this->missingCovers($mangas) as $manga) {
            $relative = $this->relative($manga);

            if (Cache::add("readex.cover.{$guard}.".sha1($relative), true, config('readex.covers.queued_seconds'))) {
                $ids[] = $manga->id;
            }
        }

        return $ids;
    }

    private function relative(Manga $manga): ?string
    {
        $file = (string) $manga->cover_file;

        if ($file === '' || basename($file) !== $file || basename((string) $manga->id) !== $manga->id) {
            return null;
        }

        return "covers/{$manga->id}/{$file}.".config('readex.covers.size').'.jpg';
    }

    private function absolute(string $relative): string
    {
        return rtrim($this->bridge->cachesPath(), '/').'/'.$relative;
    }
}
