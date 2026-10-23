<?php

namespace Tests\Support;

use App\Covers\FetchCovers;
use App\Jobs\DownloadCovers;
use App\Models\Manga;
use App\Storage\LocalStorageBridge;
use App\Storage\StorageBridge;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Native\Mobile\Testing\FakeAsyncTask;

final class Covers
{
    /** A fresh caches folder for this test; returns its path. */
    public static function useTempCaches(): string
    {
        $root = sys_get_temp_dir().'/readex-covers-'.bin2hex(random_bytes(4));
        app()->instance(StorageBridge::class, new LocalStorageBridge($root));
        register_shutdown_function(fn () => File::deleteDirectory($root));

        return $root.'/caches';
    }

    /** A real (tiny) JPEG, so the image check passes. */
    public static function jpeg(): string
    {
        $image = imagecreatetruecolor(2, 3);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    public static function manga(string $id, ?string $coverFile = 'cover.jpg', string $title = 'Series', string $rating = 'safe'): Manga
    {
        return Manga::query()->create([
            'id' => $id,
            'title' => ['en' => $title],
            'alt_titles' => [],
            'description' => [],
            'content_rating' => $rating,
            'available_languages' => ['en'],
            'tag_ids' => [],
            'cover_file' => $coverFile,
        ]);
    }

    /** @return list<string> manga ids handed to FetchCovers, in dispatch order */
    public static function fetched(FakeAsyncTask $async): array
    {
        $tasks = array_filter($async->dispatched, fn (array $d) => ($d['work']['task'] ?? null) === FetchCovers::class);

        return array_merge([], ...array_map(fn (array $d) => $d['work']['args'][0], array_values($tasks)));
    }

    /** @return list<string> every cover a screen asked for: fetched right away, then queued */
    public static function requested(FakeAsyncTask $async): array
    {
        return [...self::fetched($async), ...Queue::pushed(DownloadCovers::class)->flatMap(fn (DownloadCovers $job) => $job->mangaIds)->all()];
    }

    /** Put a thumbnail on disk as if it had been downloaded. */
    public static function store(string $caches, Manga $manga): string
    {
        $path = "{$caches}/covers/{$manga->id}/{$manga->cover_file}.256.jpg";
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, self::jpeg());

        return $path;
    }
}
