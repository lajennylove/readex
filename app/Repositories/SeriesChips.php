<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Library status chips (D41/D42/D43), series-level, not per-chapter (D14): a small pure
 * value object + a stateless computation, so the rules are unit-testable directly against
 * fixture `SeriesDownloadFacts` (CLAUDE.md §3.6) without touching the database.
 *
 * Chips are independent booleans, never mutually exclusive — a series can show several at
 * once (e.g. Downloaded + Finished).
 *
 * `newChapterAvailable` (D43) is its own badge, not a chip-row member: Downloaded/Finished are
 * judged against `releasedCount` (the app's own stored chapter rows, one per number), which
 * doesn't move until a refresh actually pulls a new chapter in — so a series that was 100%
 * Downloaded + Finished keeps both chips the moment the source's feed total grows, and only
 * gains this separate dot. It's a light-touch boolean compare of what's stored vs. the feed's
 * own reported total, both already in SQLite — no notification/polling logic.
 */
final readonly class SeriesChips
{
    public function __construct(
        public bool $downloading,
        public bool $downloaded,
        public bool $new,
        public bool $finished,
        public bool $newChapterAvailable,
    ) {}

    public static function from(SeriesDownloadFacts $facts): self
    {
        $downloaded = $facts->releasedCount > 0 && $facts->downloadedCount === $facts->releasedCount;

        return new self(
            downloading: $facts->anyInProgress,
            downloaded: $downloaded,
            new: $downloaded && ! $facts->anyChapterOpened,
            finished: $facts->downloadedCount > 0 && $facts->allDownloadedFinished,
            newChapterAvailable: $facts->downloadedCount < $facts->catalogueTotal,
        );
    }
}
