<?php

namespace Tests\Support;

use App\Models\Chapter;
use App\Models\StoredChapter;
use App\Storage\PageStore;
use Illuminate\Support\Facades\File;
use Native\Mobile\Facades\Network;
use Native\Mobile\Testing\Native;

final class Stored
{
    /** A chapter (created unless it exists) with every page on the phone, last opened at `$openedAt` (null: never opened). */
    public static function chapter(string $id, int $pages = 2, ?string $openedAt = null, string $tier = 'cache', array $attributes = []): Chapter
    {
        $chapter = Chapter::query()->find($id) ?? Pages::chapter($id, ['manga_id' => 't6-m', 'pages' => $pages, ...$attributes]);
        $store = app(PageStore::class);
        $store->begin($chapter, 'data-saver', $pages, $tier);

        foreach (range(0, $pages - 1) as $index) {
            $store->put($id, $index, Pages::image());
        }

        StoredChapter::query()->whereKey($id)->update(['last_opened_at' => $openedAt]);

        return $chapter;
    }

    /** A chapter (created unless it exists) begun with `$pages` pages, the first `$done` of them on the phone. */
    public static function partial(string $id, int $done, int $pages = 4, string $tier = 'cache', string $quality = 'data-saver', array $attributes = []): Chapter
    {
        $chapter = Chapter::query()->find($id) ?? Pages::chapter($id, ['manga_id' => 't7-m', 'pages' => $pages, ...$attributes]);
        $store = app(PageStore::class);
        $store->begin($chapter, $quality, $pages, $tier);

        for ($index = 0; $index < $done; $index++) {
            $store->put($id, $index, Pages::image());
        }

        return $chapter;
    }

    /** A fresh storage folder for this test (the local tier lives in storage/app/public/downloads); returns its path. */
    public static function useTempStorage(): string
    {
        $storage = sys_get_temp_dir().'/readex-storage-'.bin2hex(random_bytes(4));
        app()->useStoragePath($storage);
        register_shutdown_function(fn () => File::deleteDirectory($storage));

        return $storage;
    }

    /** The phone's connection as `Network::status()` reports it; null: exactly null, as without a native bridge. */
    public static function network(?array $status): void
    {
        if ($status === null) {
            Network::shouldReceive('status')->andReturn(null);

            return;
        }

        Native::fakeBridge()->respondTo('Network.Status', $status);
    }

    public static function wifi(): array
    {
        return ['connected' => true, 'type' => 'wifi', 'isExpensive' => false, 'isConstrained' => false];
    }

    public static function cellular(): array
    {
        return ['connected' => true, 'type' => 'cellular', 'isExpensive' => true, 'isConstrained' => false];
    }
}
