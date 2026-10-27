<?php

namespace App\Repositories;

use App\Adapters\Http\Client;
use App\Adapters\Http\Exceptions\ApiError;
use App\Adapters\Http\Mappers\TagMapper;
use App\Adapters\Http\RateLimit\Priority;
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

    /** Replaces the whole set: a tag the source no longer sends is removed. */
    public function refresh(Priority $priority = Priority::Interactive): void
    {
        try {
            $response = $this->client->get('/manga/tag', [], $priority);
        } catch (ApiError $e) {
            // A source that doesn't serve /manga/tag (e.g. a demo URL without the API suffix)
            // shouldn't surface as "Something went wrong"; leave tags untouched instead.
            if (in_array($e->status, [404, 405], true)) {
                return;
            }

            throw $e;
        }

        $rows = TagMapper::rows($response);

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
