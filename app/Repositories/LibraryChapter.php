<?php

namespace App\Repositories;

use App\Models\Chapter;
use App\Models\ReadingProgress;

/** One chapter row of the Collection screen. `otherGroup`: the preferred group has no upload of this number. */
final readonly class LibraryChapter
{
    public function __construct(
        public Chapter $chapter,
        public ?ReadingProgress $progress,
        public bool $otherGroup = false,
    ) {}
}
