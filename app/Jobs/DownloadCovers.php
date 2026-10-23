<?php

namespace App\Jobs;

use App\Covers\CoverStore;
use App\MangaDex\Exceptions\ApiError;
use App\MangaDex\Exceptions\RequestDeferred;
use App\MangaDex\RateLimit\Priority;
use App\Models\Manga;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;

/**
 * Covers for a set of tiles, in the order given. No slot → release until the controller's
 * retry time; offline → try again in a minute (stored covers are skipped on every run);
 * a broken cover (404, not an image) → skip it.
 */
class DownloadCovers implements ShouldQueue
{
    use Queueable;

    public const GIVE_UP_AFTER_SECONDS = 3600;

    public const OFFLINE_RETRY_SECONDS = 60;

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
