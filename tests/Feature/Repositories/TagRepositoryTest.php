<?php

use App\Models\Tag;
use App\Repositories\TagRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\MangaDexFixtures;

beforeEach(function () {
    Carbon::setTestNow('2026-10-24 12:00:00');
    Sleep::fake(syncWithCarbon: true);
    $this->tags = app(TagRepository::class);
});

it('answers empty and stale before the first refresh', function () {
    $result = $this->tags->get();

    expect($result->isEmpty())->toBeTrue()
        ->and($result->isStale)->toBeTrue()
        ->and($result->fetchedAt)->toBeNull();
});

it('saves every tag, sorted by group then English name', function () {
    MangaDexFixtures::fake('tags');

    $this->tags->refresh();
    $result = $this->tags->get();

    expect($result->data)->toHaveCount(77)
        ->and($result->isStale)->toBeFalse()
        ->and($result->fetchedAt->toDateTimeString())->toBe('2026-10-24 12:00:00')
        ->and($result->data->first()->group)->toBe('content')
        ->and($result->data->firstWhere('id', 'ea2bc92d-1c26-4930-9b7c-d5c0dc1b6869')->name['en'])->toBe('Cooking');

    $groups = $result->data->pluck('group')->all();
    $sorted = $groups;
    sort($sorted);
    expect($groups)->toBe($sorted);
});

it('removes tags MangaDex no longer sends', function () {
    Tag::create(['id' => '00000000-0000-0000-0000-000000000000', 'group' => 'genre', 'name' => ['en' => 'Gone'], 'fetched_at' => Carbon::now()]);
    MangaDexFixtures::fake('tags');

    $this->tags->refresh();

    expect(Tag::find('00000000-0000-0000-0000-000000000000'))->toBeNull()
        ->and(Tag::count())->toBe(77);
});

it('goes stale after 30 days and keeps the tags when offline', function () {
    MangaDexFixtures::fake('tags');
    $this->tags->refresh();

    Carbon::setTestNow(Carbon::now()->addDays(30));
    Http::fake(fn () => throw new ConnectionException('offline'));

    expect(fn () => $this->tags->refresh())->toThrow(ConnectionException::class);

    $result = $this->tags->get();
    expect($result->data)->toHaveCount(77)
        ->and($result->isStale)->toBeTrue();
});
