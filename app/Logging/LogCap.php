<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Support\Facades\File;

/**
 * Last-resort size backstop for `storage/logs/laravel.log` (incident 2026-10-25: a stuck queue
 * job logged the same exception on every retry, all night, until the single/daily log file hit
 * 35 GB and filled the device's disk). Whichever Monolog driver is configured for the app's log
 * channels, this keeps the active log file from growing without bound: truncated in place, so a
 * tool already holding the file descriptor (Monolog's own handler) keeps writing to the same
 * inode instead of a renamed-away file nobody reads.
 */
final class LogCap
{
    /**
     * Truncate the active Laravel log file(s) once they pass `logging.max_log_bytes`.
     *
     * Covers both the `single` driver (`laravel.log`) and the `daily` driver
     * (`laravel-YYYY-MM-DD.log`) — a stuck job retrying all day can still fill a single
     * day's dated file faster than the next rotation lands, so the backstop has to see
     * the dated files too.
     */
    public function enforce(): void
    {
        $cap = (int) config('readex.logging.max_log_bytes');

        if ($cap <= 0) {
            return;
        }

        $paths = array_merge(
            (array) glob(storage_path('logs/laravel.log')),
            (array) glob(storage_path('logs/laravel-*.log')),
        );

        foreach ($paths as $path) {
            if (! is_file($path) || filesize($path) <= $cap) {
                continue;
            }

            File::put($path, '['.now()->toIso8601String()."] log truncated: exceeded {$cap} bytes (disk-space guardrail)\n");
        }
    }
}
