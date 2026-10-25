<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Logging\LogCap;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Last-resort backstop for the overnight disk-fill incident (2026-10-25): whichever Monolog
 * driver is active, no single `storage/logs/laravel.log` may grow past `logging.max_log_bytes`.
 * The `daily` driver rotates by date, not by size within a day, so a job retrying at full speed
 * for hours can still fill one day's file; this truncates it back down regardless. Unique until
 * it starts, matching EnforceCacheCap's pattern so a burst of triggers queues at most one more run.
 */
class EnforceLogCap implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function handle(LogCap $cap): void
    {
        $cap->enforce();
    }
}
