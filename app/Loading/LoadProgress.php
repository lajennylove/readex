<?php

namespace App\Loading;

use Illuminate\Support\Facades\Cache;

/**
 * Per-step state of one DataLoader run, in the cache. The loader runs on the async lane
 * (Setup) or the queue worker (Refresh MangaDex data), both other PHP runtimes, and
 * AsyncTask has no progress channel, so the screen polls this.
 */
final class LoadProgress
{
    public const RUNNING = 'running';

    private const TTL_SECONDS = 3600;

    public static function start(string $run): void
    {
        Cache::put(self::key($run), [], self::TTL_SECONDS);
    }

    /** `$state` is RUNNING or a RefreshStatus value. */
    public static function record(string $run, string $step, string $state): void
    {
        Cache::put(self::key($run), [...self::read($run), $step => $state], self::TTL_SECONDS);
    }

    /** @return array<string, string> step => state, only for steps that started */
    public static function read(string $run): array
    {
        $steps = Cache::get(self::key($run));

        return is_array($steps) ? $steps : [];
    }

    private static function key(string $run): string
    {
        return "readex.loading.{$run}";
    }
}
