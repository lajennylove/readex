<?php

namespace App\Repositories;

use App\Models\Chapter;
use App\Models\Manga;
use App\Models\ReadingProgress;

/** One Home "Continue reading" tile. `progress` is null when the tile is the next, unopened chapter. */
final readonly class ContinueReading
{
    public function __construct(
        public Manga $manga,
        public Chapter $chapter,
        public ?ReadingProgress $progress,
    ) {}
}
