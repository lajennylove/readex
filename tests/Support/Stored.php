<?php

namespace Tests\Support;

use App\Models\Chapter;
use App\Models\StoredChapter;
use App\Storage\PageStore;
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
