<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * The series-level counts §3 needs to derive its download/read chip states — pure inputs so
 * `SeriesChips::from()` is cheap to unit-test with fixtures (CLAUDE.md §3.6).
 */
final readonly class SeriesDownloadFacts
{
    public function __construct(
        /** Chapters the app knows locally (one per number, D14 chain) — what Downloaded/New are judged against, never a live API hit. */
        public int $releasedCount,
        /** Of those, how many are fully stored (`status = done`, local or iCloud tier). */
        public int $downloadedCount,
        /** At least one chapter of the series is queued or actively downloading right now. */
        public bool $anyInProgress,
        /** `reading_progress` has a row for any chapter of the series, any upload. */
        public bool $anyChapterOpened,
        /** Every downloaded chapter (the `downloadedCount` above) has `finished_at` set; vacuously true at 0. */
        public bool $allDownloadedFinished,
        /**
         * D43: the series' chapter feed's own reported total (`chapter_feeds.total`), already in
         * SQLite — independent of `releasedCount`, which only grows once a chapter's full row is
         * stored. A gap between the two means the source has released something the feed total
         * already reflects that the app hasn't pulled chapter rows for yet.
         */
        public int $catalogueTotal,
    ) {}
}
