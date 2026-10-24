<?php

namespace App\Jobs;

use App\Storage\ImageCache;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Spec §5.6: trims the cache tier to the cap after a cache write. Unique until it starts, so a
 * write during a run queues the next one.
 */
class EnforceCacheCap implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function handle(ImageCache $cache): void
    {
        $cache->enforce();
    }
}
