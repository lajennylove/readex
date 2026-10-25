<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Adapters\Http\Exceptions\ApiError;
use App\Adapters\Http\Exceptions\RequestDeferred;
use App\Adapters\Http\RateLimit\Priority;
use App\Covers\CoverStore;
use App\Models\Manga;
use App\Storage\Exceptions\DiskSpaceLow;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;

/**
 * Covers for a set of tiles, in the order given. No slot → release until the controller's
 * retry time; offline → try again in a minute (stored covers are skipped on every run);
 * a broken cover (404, not an image) → skip it; the device is nearly out of room → release
 * until the offline retry window too, rather than hammering CoverStore::download() again
 * immediately.
 *
 * Incident guardrail (2026-10-25): an unexpected Throwable from handle() (not one of the
 * explicit catches below) previously had no `$tries` cap and no backoff, so the queue worker
 * could re-run it continuously until `retryUntil()` (one hour) — each attempt re-logging the
 * same stack trace. `backoff()` spaces those attempts out so a runaway loop can no longer fire
 * as fast as the worker can cycle; `$tries` additionally caps it once `retryUntil()` has passed
 * (Laravel's worker honours whichever of the two is still open, so `retryUntil()` is the
 * effective ceiling for the first hour and `$tries` guards what happens after a release resets
 * the attempt count, e.g. the DiskSpaceLow/offline release paths below).
 */
class DownloadCovers implements ShouldQueue
{
    use Queueable;

    public const GIVE_UP_AFTER_SECONDS = 3600;

    public const OFFLINE_RETRY_SECONDS = 60;

    /** A genuinely unexpected Throwable (not one of the explicit catches) gets this many tries. */
    public int $tries = 5;

    /** Seconds before each of those attempts; the last value repeats past 5. */
    public array $backoff = [10, 30, 60, 120];

    /** @param  list<string>  $mangaIds */
    public function __construct(
        public readonly array $mangaIds,
        public readonly Priority $priority,
    ) {}

    public function retryUntil(): CarbonInterface
    {
        return Carbon::now()->addSeconds(self::GIVE_UP_AFTER_SECONDS);
    }

    public function handle(CoverStore $covers): void
    {
        $mangas = Manga::query()->whereIn('id', $this->mangaIds)->get()->keyBy('id');

        foreach ($this->mangaIds as $id) {
            $manga = $mangas->get($id);

            if ($manga === null) {
                continue;
            }

            try {
                $covers->download($manga, $this->priority);
            } catch (RequestDeferred $e) {
                $this->release($this->secondsUntil($e->busy->retryAt));

                return;
            } catch (ConnectionException) {
                $this->release(self::OFFLINE_RETRY_SECONDS);

                return;
            } catch (DiskSpaceLow) {
                $this->release(self::OFFLINE_RETRY_SECONDS);

                return;
            } catch (ApiError) {
                continue;
            }
        }
    }

    private function secondsUntil(float $retryAt): int
    {
        return max(1, (int) ceil($retryAt - Carbon::now()->getTimestampMs() / 1000));
    }
}
