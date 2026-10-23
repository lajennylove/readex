<?php

namespace Tests\Support;

use App\Models\Manga;
use App\Storage\LocalStorageBridge;
use App\Storage\StorageBridge;
use Illuminate\Support\Facades\File;

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

    public static function manga(string $id, ?string $coverFile = 'cover.jpg', string $title = 'Series'): Manga
    {
        return Manga::query()->create([
            'id' => $id,
            'title' => ['en' => $title],
            'alt_titles' => [],
            'description' => [],
            'content_rating' => 'safe',
            'available_languages' => ['en'],
            'tag_ids' => [],
            'cover_file' => $coverFile,
        ]);
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
