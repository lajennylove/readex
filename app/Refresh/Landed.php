<?php

namespace App\Refresh;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Where a RefreshRepository job leaves its final outcome. The job runs on the phone's queue
 * worker (another PHP runtime), so the waiting screen polls this instead of getting a callback.
 */
final class Landed
{
    private const TTL_SECONDS = 3600;

    public static function put(Refresh $refresh, Outcome $outcome): void
    {
        Cache::put(self::cacheKey($refresh->key()), [
            'outcome' => $outcome->toArray(),
            'at' => Carbon::now()->getTimestampMs(),
        ], self::TTL_SECONDS);
    }

    /** The outcome if the job finished at or after `$sinceMs`, else null. */
    public static function since(string $key, int $sinceMs): ?Outcome
    {
        $landed = Cache::get(self::cacheKey($key));

        if (! is_array($landed) || ($landed['at'] ?? 0) < $sinceMs) {
            return null;
        }

        return Outcome::fromArray($landed['outcome']);
    }

    private static function cacheKey(string $key): string
    {
        return "readex.refresh.landed.{$key}";
    }
}
