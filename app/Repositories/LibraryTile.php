<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Manga;

/**
 * One Library grid tile's own data (spec §1). Cover/title/overall-progress-label come from
 * `ShowsTiles::tile()` + `CoverStore` in the screen layer; this carries what only the
 * repository knows, including the status chips (D41/D42/D43) so the Blade can render them
 * without recomputing `SeriesChips` itself.
 */
final readonly class LibraryTile
{
    public function __construct(
        public Manga $manga,
        public bool $pinned,
        public bool $favorite,
        public SeriesChips $chips,
        /** Distinct chapters stored `done` in the local/iCloud tier — merely-cached chapters don't count. */
        public int $downloadedChapters,
        /** One per chapter number in the reading chain (D14); 0 when the series has no known chapters yet. */
        public int $totalChapters,
        /** Distinct chapters with `reading_progress.finished_at` set, any language. */
        public int $readChapters,
        /** Total bytes on disk for this series' stored chapters, any tier. */
        public int $bytesOnDisk,
    ) {}
}
