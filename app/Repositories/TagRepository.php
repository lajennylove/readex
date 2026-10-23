<?php

namespace App\Repositories;

use App\MangaDex\Client;
use App\MangaDex\Mappers\TagMapper;
use App\MangaDex\RateLimit\Priority;
use App\Models\Tag;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class TagRepository
{
    public function __construct(private readonly Client $client) {}

    /** All tags, by group then English name. */
    public function get(): Result
    {
        $tags = Tag::query()->get()
            ->sortBy([['group', 'asc'], fn (Tag $a, Tag $b) => strcasecmp($a->name['en'] ?? '', $b->name['en'] ?? '')])
            ->values();

        return Freshness::result($tags, $tags->min('fetched_at'), 'tags');
    }

    /** Replaces the whole set: a tag MangaDex no longer sends is removed. */
    public function refresh(Priority $priority = Priority::Interactive): void
    {
        $rows = TagMapper::rows($this->client->get('/manga/tag', [], $priority));

        if ($rows === []) {
            return;
        }

        $now = Carbon::now();

        DB::transaction(function () use ($rows, $now) {
            Tag::query()->whereNotIn('id', array_column($rows, 'id'))->delete();

            foreach ($rows as $row) {
                Tag::updateOrCreate(['id' => $row['id']], [...$row, 'fetched_at' => $now]);
            }
        });
    }
}
