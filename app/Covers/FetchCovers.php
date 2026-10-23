<?php

namespace App\Covers;

use App\Jobs\DownloadCovers;
use App\MangaDex\Exceptions\ApiError;
use App\MangaDex\Exceptions\RequestDeferred;
use App\MangaDex\RateLimit\Priority;
use App\Models\Manga;
use Illuminate\Http\Client\ConnectionException;
use Native\Mobile\AsyncTask;
use Throwable;

/**
 * The covers a screen shows right now, downloaded at once on the async lane: the phone's queue
 * worker runs jobs oldest first with a pause after each, so these would wait behind every
 * prefetch batch. A broken cover (404, not an image) is skipped; no slot, offline or an
 * unexpected error → the rest goes to a DownloadCovers job. Never throws.
 */
class FetchCovers extends AsyncTask
{
    /** Seconds to wait for a slot before handing the rest to the queue. */
    public const MAX_WAIT_SECONDS = 2.0;

    /**
     * @param  list<string>  $mangaIds
     * @return array{fetched: int, queued: list<string>}
     */
    public function handle(array $mangaIds): array
    {
        $fetched = 0;
        $rest = $mangaIds;

        try {
            $covers = app(CoverStore::class);
            $mangas = Manga::query()->whereIn('id', $mangaIds)->get()->keyBy('id');

            while ($rest !== []) {
                $manga = $mangas->get($rest[0]);

                try {
                    if ($manga !== null && $covers->download($manga, Priority::Interactive, self::MAX_WAIT_SECONDS)) {
                        $fetched++;
                    }
                } catch (ApiError) {
                }

                array_shift($rest);
            }
        } catch (RequestDeferred|ConnectionException) {
        } catch (Throwable $e) {
            report($e);
        }

        if ($rest !== []) {
            DownloadCovers::dispatch($rest, Priority::Interactive);
        }

        return ['fetched' => $fetched, 'queued' => $rest];
    }
}
