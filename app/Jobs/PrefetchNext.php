<?php

namespace App\Jobs;

use App\MangaDex\RateLimit\Priority;
use App\Models\Chapter;
use App\Repositories\LibraryRepository;
use App\Storage\PageStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Native\Mobile\Facades\Network;

/**
 * Spec §5.6, D33 choice 6: queues the next chapters after the one being read into the cache tier.
 * Licensed chapters are skipped; a chapter already on the phone counts as prefetched.
 */
class PrefetchNext implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $mangaId,
        public readonly string $fromChapterId,
    ) {}

    /**
     * Queue it when the phone is connected and neither on a metered connection (cellular,
     * personal hotspot) nor in Low Data Mode; unknown (no native bridge) is no. Checked by the
     * reader, in the UI runtime.
     */
    public static function dispatchIfUnmetered(string $mangaId, string $fromChapterId): bool
    {
        $network = Network::status();

        if (($network->connected ?? false) !== true || ($network->isExpensive ?? false) || ($network->isConstrained ?? false)) {
            return false;
        }

        self::dispatch($mangaId, $fromChapterId);

        return true;
    }

    public function handle(LibraryRepository $library, PageStore $pages): void
    {
        $chapter = Chapter::query()->where('manga_id', $this->mangaId)->find($this->fromChapterId);
        $left = config('readex.cache.prefetch_chapters');

        while ($chapter !== null && $left > 0) {
            $chapter = $library->after($chapter);

            if ($chapter === null || $chapter->external_url !== null) {
                continue;
            }

            if (! $pages->has($chapter->id)) {
                DownloadChapter::dispatch($chapter->id, 'cache', Priority::Prefetch);
            }

            $left--;
        }
    }
}
