<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Storage\ImageCache;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Spec §5.6: trims the cache tier to the cap after a cache write. Unique until it starts, so a
 * write during a run queues the next one.
 *
 * Incident guardrail (2026-10-25): a capped `$tries`/`backoff()` so a filesystem error here
 * (same class of bug as the original incident) retries a bounded number of times instead of at
 * full speed forever.
 */
class EnforceCacheCap implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public int $tries = 5;

    public array $backoff = [10, 30, 60, 120];

    public function handle(ImageCache $cache): void
    {
        $cache->enforce();
    }
}
