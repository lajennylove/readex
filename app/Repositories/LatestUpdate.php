<?php

namespace App\Repositories;

use App\Models\Chapter;
use App\Models\Manga;

/** One Latest updates tile: the series and its newest chapter in the reading chain. */
final readonly class LatestUpdate
{
    public function __construct(
        public Manga $manga,
        public Chapter $chapter,
    ) {}
}
