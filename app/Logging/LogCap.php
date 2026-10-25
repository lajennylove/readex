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
    /** Truncate `storage/logs/laravel.log` once it passes `logging.max_log_bytes`. */
    public function enforce(): void
    {
        $path = storage_path('logs/laravel.log');

        if (! is_file($path)) {
            return;
        }

        $cap = (int) config('readex.logging.max_log_bytes');

        if ($cap <= 0 || filesize($path) <= $cap) {
            return;
        }

        File::put($path, '['.now()->toIso8601String()."] log truncated: exceeded {$cap} bytes (disk-space guardrail)\n");
    }
}
