<?php

use App\Adapters\Http\Exceptions\RequestDeferred;
use App\Adapters\Http\RateLimit\Busy;
use App\Adapters\Http\RateLimit\BusyReason;
use App\Adapters\Http\RateLimit\Priority;
use App\Jobs\DownloadCovers;
use App\Jobs\RefreshRepository;
use App\Models\MangaListEntry;
use App\NativeComponents\Search;
use App\Repositories\MangaRepository;
use App\Repositories\SettingsRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Native\Mobile\AsyncTask;
use Native\Mobile\Edge\TailwindParser;
use Native\Mobile\Testing\Native;
use Tests\Support\Covers;
use Tests\Support\MangaDexFixtures;
use Tests\Support\Skeletons;

const COOKING_TAG = 'ea2bc92d-1c26-4930-9b7c-d5c0dc1b6869';

beforeEach(function () {
    Carbon::setTestNow('2026-10-24 12:00:00');
    Sleep::fake(syncWithCarbon: true);
    Covers::useTempCaches();
    app(SettingsRepository::class)->setLanguage('es-la');
    // The recorded searches come in pages of 10.
    config(['readex.page_sizes.list' => 10]);
    $this->async = AsyncTask::fake();
    Queue::fake();
    $this->asked = [];
    /** `$override` answers a request first (return null to pass it on to the fixtures). */
    $this->fake = function (?Closure $override = null): void {
        Http::swap(new Factory(app('events')));
        $fixtures = MangaDexFixtures::stub('tags', 'search-one-punch', 'search-one-punch-p1', 'search-one-punch-p2', 'browse-cooking');

        Http::fake(function (Request $request) use ($override, $fixtures) {
            if (! str_contains($request->url(), 'uploads.mangadex.org/covers/')) {
                $this->asked[] = rawurldecode($request->url());
            }

            if ($override !== null && ($answer = $override($request)) !== null) {
                return $answer;
            }

            return $fixtures($request);
        });
    };
    ($this->fake)();
});

afterEach(fn () => AsyncTask::clearFake());

/** Every text in the last render, in screen order. */
function searchTexts(array $node): array
{
    $texts = in_array($node['type'] ?? null, ['text', 'chip'], true) ? [$node['props']['text'] ?? $node['props']['label']] : [];

    foreach ($node['children'] ?? [] as $child) {
        $texts = [...$texts, ...searchTexts($child)];
    }

    return $texts;
}

/** @return list<array> every node of this type */
function searchNodes(array $node, string $type): array
{
    $found = ($node['type'] ?? null) === $type ? [$node] : [];

    foreach ($node['children'] ?? [] as $child) {
        $found = [...$found, ...searchNodes($child, $type)];
    }

    return $found;
}

/** Tile titles in grid order (the field's clear button is the one pressable without text). */
function tileTitles(array $tree): array
{
    $tiles = array_filter(searchNodes($tree, 'pressable'), fn (array $node) => searchNodes($node, 'text') !== []);

    return array_values(array_map(fn (array $tile) => searchNodes($tile, 'text')[0]['props']['text'], $tiles));
}

function asked(array $asked, string $needle): int
{
    return count(array_filter($asked, fn (string $url) => str_contains($url, $needle)));
}

function busy(): RequestDeferred
{
    return new RequestDeferred(new Busy(Carbon::now()->addMinute()->getTimestampMs() / 1000, BusyReason::Saturated));
}

it('puts the search field at the top of the screen, not in the nav bar (iOS tucks a tab nav-bar field away until pulled down)', function () {
    $screen = Native::visit('/search');
    $fields = searchNodes($screen->tree(), 'bare_text_input');

    expect($screen->tree()['props'])->not->toHaveKey('nav_search_placeholder')
        ->and($fields)->toHaveCount(1)
        ->and($fields[0]['props'])->toMatchArray(['placeholder' => 'Titles', 'value' => '', 'sync_mode' => 'debounce', 'debounce_ms' => 400])
        ->and($fields[0]['props']['on_change'])->toBeInt()
        ->and(searchNodes($screen->tree(), 'icon'))->toHaveCount(1);

    $screen->call('searchFor', 'one punch');
    expect(searchNodes($screen->tree(), 'bare_text_input'))->toHaveCount(1);
});

it('searches as the field is typed in, keeping the typed text as it is', function () {
    $screen = Native::test(Search::class)->input('searchFor', 'one ');

    $screen->assertSet('query', 'one')->assertSet('text', 'one ');
    expect(searchNodes($screen->tree(), 'bare_text_input')[0]['props']['value'])->toBe('one ');
});

it('shows the tag browser for an empty query, grouped Genre, Theme, Format, Content', function () {
    $screen = Native::test(Search::class)->assertSee('Cooking')->assertSet('virtualWindowTo', 14);

    $texts = searchTexts($screen->tree());
    $at = fn (string $label) => array_search($label, $texts, true);

    expect($at('Genre'))->toBeLessThan($at('Theme'))
        ->and($at('Theme'))->toBeLessThan($at('Format'))
        ->and($at('Format'))->toBeLessThan($at('Content'))
        ->and($at('Action'))->toBeGreaterThan($at('Genre'))->toBeLessThan($at('Theme'))
        ->and($at('Cooking'))->toBeGreaterThan($at('Theme'))->toBeLessThan($at('Format'))
        ->and($at('Gore'))->toBeGreaterThan($at('Content'))
        ->and(searchNodes($screen->tree(), 'chip'))->toHaveCount(77)
        ->and(asked($this->asked, '/manga/tag'))->toBe(1);
});

it('shows pulsing tag groups while the tags are on their way', function () {
    ($this->fake)(fn (Request $request) => str_contains($request->url(), '/manga/tag') ? throw busy() : null);

    $collisions = Skeletons::keyCollisions(fn () => $this->tree = Native::test(Search::class)->assertDontSee('No tags saved yet')->tree());
    $tree = $this->tree;

    expect($collisions)->toBe([])
        ->and(searchNodes($tree, 'activity_indicator'))->toBe([])
        ->and(searchNodes($tree, 'chip'))->toBe([])
        ->and(Skeletons::pulsing($tree))->toHaveCount(3 * (1 + 2 * 5))
        ->and(array_unique(array_column(Skeletons::all($tree), 'id')))->toHaveCount(33);
});

it('lays each tag group out as up to 3 chip lines scrolling sideways, never a wrapping row (iOS ignores flex-wrap)', function () {
    $tree = Native::test(Search::class)->tree();
    $wrapping = array_filter(searchNodes($tree, 'row'), fn (array $row) => ($row['layout']['flex_wrap'] ?? 0) !== 0);
    $scrolls = searchNodes($tree, 'scroll_view');
    $lines = fn (array $scroll) => array_map(
        fn (array $row) => array_map(fn (array $chip) => $chip['props']['label'], searchNodes($row, 'chip')),
        searchNodes($scroll, 'row'),
    );

    expect($wrapping)->toBe([])
        ->and($scrolls)->toHaveCount(4)
        ->and(array_unique(array_map(fn (array $s) => $s['props']['axis'] ?? null, $scrolls)))->toBe(['horizontal'])
        ->and(array_sum(array_map(fn (array $s) => count(searchNodes($s, 'chip')), $scrolls)))->toBe(77);

    foreach ($scrolls as $scroll) {
        $chips = array_merge(...$lines($scroll));
        $sorted = $chips;
        natcasesort($sorted);

        expect(count($lines($scroll)))->toBeLessThanOrEqual(3)
            ->and($chips)->toBe(array_values($sorted));
    }

    expect(count($lines($scrolls[1])))->toBe(3);
});

it("shows a tag's series when its chip is tapped, and the tags again when it's cleared", function () {
    $screen = Native::test(Search::class)->toggle("openTag('".COOKING_TAG."')", true);

    $titles = tileTitles($screen->tree());
    expect($titles)->toHaveCount(10)
        ->and(asked($this->asked, 'includedTags[]='.COOKING_TAG))->toBe(1)
        ->and(searchNodes($screen->tree(), 'chip'))->toHaveCount(1)
        ->and(searchNodes($screen->tree(), 'chip')[0]['props'])->toMatchArray(['label' => 'Cooking', 'value' => true]);

    $screen->toggle('clearTag', false)->assertSee('Genre');
    expect(searchNodes($screen->tree(), 'chip'))->toHaveCount(77);
});

it('searches titles from the search field, in reading-language order with badges', function () {
    $screen = Native::test(Search::class)->call('searchFor', '  one   punch ')->assertSet('query', 'one punch');
    expect($screen->get('more'))->toBeTrue();

    $chain = app(SettingsRepository::class)->chain();
    $results = app(MangaRepository::class)->search('one punch')->data;
    $badges = searchNodes($screen->tree(), 'badge');

    expect(tileTitles($screen->tree()))->toBe($results->map(fn ($m) => $chain->title($m->title, $m->alt_titles ?? []))->all())
        ->and(searchNodes($screen->tree(), 'virtual_list')[0]['props'])->toMatchArray(['count' => 4])->toHaveKey('on_window_change')
        ->and($badges)->not->toBeEmpty()
        ->and(count($badges))->toBeLessThan(10)
        ->and(asked($this->asked, 'title=one punch'))->toBe(1);

    // Same text again (the field re-sends after a cancel/refocus): no new request.
    $screen->call('searchFor', 'one punch');
    expect(asked($this->asked, 'title=one punch'))->toBe(1);

    $screen->call('searchFor', '')->assertSet('query', '')->assertSee('Genre');
});

it('searches inside the chosen tag', function () {
    ($this->fake)(fn (Request $request) => str_contains(rawurldecode($request->url()), 'title=') ? Http::response(MangaDexFixtures::body('search-one-punch-p2')) : null);

    $screen = Native::test(Search::class)
        ->toggle("openTag('".COOKING_TAG."')", true)
        ->call('searchFor', 'one punch');

    expect(collect($this->asked)->last())->toContain('title=one punch')->toContain('includedTags[]='.COOKING_TAG)
        ->and(tileTitles($screen->tree()))->toHaveCount(8)
        ->and(searchNodes($screen->tree(), 'chip')[0]['props']['label'])->toBe('Cooking');
});

it('keeps the title search when Search is opened again (every tab tap makes a new screen)', function () {
    Native::test(Search::class)->call('searchFor', 'one punch');
    $titles = tileTitles(Native::test(Search::class)->tree());

    $screen = Native::test(Search::class)->assertSet('query', 'one punch');

    expect(tileTitles($screen->tree()))->toHaveCount(10)->toBe($titles)
        ->and(asked($this->asked, 'title=one punch'))->toBe(1);
});

it('keeps the chosen tag, with its selected chip, when Search is opened again', function () {
    Native::test(Search::class)->toggle("openTag('".COOKING_TAG."')", true);

    $screen = Native::test(Search::class)->assertSet('tagId', COOKING_TAG);

    expect(tileTitles($screen->tree()))->toHaveCount(10)
        ->and(searchNodes($screen->tree(), 'chip'))->toHaveCount(1)
        ->and(searchNodes($screen->tree(), 'chip')[0]['props'])->toMatchArray(['label' => 'Cooking', 'value' => true]);
});

it('starts fresh after the field is emptied or the tag chip is deselected', function () {
    Native::test(Search::class)->toggle("openTag('".COOKING_TAG."')", true)->call('searchFor', 'one punch')->call('searchFor', '');
    Native::test(Search::class)->assertSet('query', '')->assertSet('tagId', COOKING_TAG)->toggle('clearTag', false);

    Native::test(Search::class)->assertSet('query', '')->assertSet('tagId', null)->assertSee('Genre');
});

it('puts the kept title back in the field, and its clear button empties it', function () {
    Native::test(Search::class)->call('searchFor', 'one punch');

    $screen = Native::test(Search::class);
    expect(searchNodes($screen->tree(), 'bare_text_input')[0]['props']['value'])->toBe('one punch')
        ->and(searchNodes($screen->tree(), 'chip'))->toBe([])
        ->and(searchNodes($screen->tree(), 'icon'))->toHaveCount(2);

    $screen->press('clearQuery')->assertSet('query', '')->assertSet('text', '')->assertSee('Genre');
    expect(searchNodes($screen->tree(), 'bare_text_input')[0]['props']['value'])->toBe('')
        ->and(searchNodes($screen->tree(), 'icon'))->toHaveCount(1);
    Native::test(Search::class)->assertSet('query', '')->assertSee('Genre');
});

it('shows only the tag as a chip when a title and a tag are kept', function () {
    Native::test(Search::class)->toggle("openTag('".COOKING_TAG."')", true)->call('searchFor', 'one punch');

    $screen = Native::test(Search::class);

    expect(array_map(fn (array $chip) => $chip['props']['label'], searchNodes($screen->tree(), 'chip')))->toBe(['Cooking'])
        ->and(searchNodes($screen->tree(), 'bare_text_input')[0]['props']['value'])->toBe('one punch');
});

it('leaves the same room between the field and the results with or without a tag chip', function () {
    $holds = fn (array $node, string $type) => array_filter($node['children'] ?? [], fn (array $child) => $child['type'] === $type) !== [];
    $holding = fn (array $tree, string $type) => array_values(array_filter(searchNodes($tree, 'row'), fn (array $row) => $holds($row, $type)))[0];
    $below = fn (array $tree) => array_values(array_filter(
        searchNodes($tree, 'column'),
        fn (array $column) => array_filter($column['children'] ?? [], fn (array $child) => $holds($child, 'bare_text_input')) !== [],
    ))[0]['layout']['padding'][2];

    $screen = Native::test(Search::class)->call('searchFor', 'one punch');
    expect(searchNodes($screen->tree(), 'chip'))->toBe([]);
    $plain = $below($screen->tree());

    $screen->toggle("openTag('".COOKING_TAG."')", true);
    $tagged = $below($screen->tree()) + $holding($screen->tree(), 'chip')['layout']['padding'][0];

    expect($plain)->toBe($tagged)->toBe(12.0);
});

it('forgets a kept tag that is no longer saved', function () {
    app(SettingsRepository::class)->rememberSearch('', 'not-a-saved-tag');

    Native::test(Search::class)->assertSet('tagId', null)->assertSee('Genre');
});

it('loads the next batch once when the window nears the end, earlier tiles keep their place (Review Focus 5)', function () {
    $screen = Native::test(Search::class)->call('searchFor', 'one punch');
    $first = tileTitles($screen->tree());

    // Several window changes arrive at once.
    $screen->call('windowChanged', 0, 3)->call('windowChanged', 0, 4)->call('windowChanged', 0, 5);

    expect(asked($this->asked, 'offset=10'))->toBe(1)
        ->and(array_slice(tileTitles($screen->tree()), 0, 10))->toBe($first)
        ->and(tileTitles($screen->tree()))->toHaveCount(18)
        ->and(searchNodes($screen->tree(), 'virtual_list')[0]['props'])->toMatchArray(['count' => 6]);

    // The field sends the same text again (refocus): nothing starts over.
    expect($screen->get('more'))->toBeFalse();
    $screen->call('searchFor', 'one punch')->assertSet('virtualWindowTo', 5);
    expect($screen->get('more'))->toBeFalse();
});

it('asks for one batch while it waits for a busy MangaDex, then picks up the job result', function () {
    ($this->fake)(fn (Request $request) => str_contains($request->url(), 'offset=10') ? throw busy() : null);
    $screen = Native::test(Search::class)->call('searchFor', 'one punch');

    $screen->call('windowChanged', 0, 3)->call('windowChanged', 0, 4)->call('windowChanged', 0, 5)
        ->assertSee('The provider is busy, will update shortly');

    expect(asked($this->asked, 'offset=10'))->toBe(1);
    Queue::assertPushed(RefreshRepository::class, 1);

    ($this->fake)();
    Queue::pushed(RefreshRepository::class)->first()->handle();
    $screen->firePolls()->assertDontSee('The provider is busy');

    expect($screen->get('more'))->toBeFalse()
        ->and(tileTitles($screen->tree()))->toHaveCount(18);
});

it('waits for the window to get near the end before loading more', function () {
    $key = app(MangaRepository::class)->listKey('one punch');

    foreach (range(0, 59) as $i) {
        $manga = Covers::manga(sprintf('00000000-0000-4000-8000-%012d', $i), title: "Series {$i}");
        MangaListEntry::query()->insert(['list_key' => $key, 'batch' => 1, 'position' => $i, 'manga_id' => $manga->id, 'fetched_at' => Carbon::now()]);
    }
    ($this->fake)(fn (Request $request) => str_contains($request->url(), 'offset=60')
        ? Http::response(['result' => 'ok', 'response' => 'collection', 'data' => [], 'limit' => 10, 'offset' => 60, 'total' => 60])
        : null);

    // 60 saved and fresh (20 rows): nothing asked, and nothing known about more.
    $screen = Native::test(Search::class)->call('searchFor', 'one punch');
    $ids = app(MangaRepository::class)->search('one punch')->data->pluck('id')->all();
    expect($screen->get('more'))->toBeNull()
        ->and(Covers::requested($this->async))->toBe(array_slice($ids, 0, 45));

    $this->asked = [];
    $screen->call('windowChanged', 0, 14);
    expect($this->asked)->toBe([])
        ->and(tileTitles($screen->tree()))->toHaveCount(45);

    $screen->call('windowChanged', 4, 15);
    expect($screen->get('more'))->toBeFalse()
        ->and(asked($this->asked, 'offset=60'))->toBe(1)
        ->and(searchNodes($screen->tree(), 'virtual_list')[0]['props'])->toMatchArray(['window_from' => 4, 'window_to' => 15]);

    // Past the 60 s no-loop guard: still nothing asked, MangaDex said there's no more.
    Carbon::setTestNow(Carbon::now()->addMinutes(2));
    $screen->call('windowChanged', 5, 19);
    expect($this->asked)->toHaveCount(1);
});

it('puts rated content (erotica, pornographic) at the bottom, under a divider and its own title', function () {
    app(SettingsRepository::class)->setContentRatings(['safe', 'suggestive', 'erotica', 'pornographic']);
    $key = app(MangaRepository::class)->listKey('one punch');
    $saved = [
        1 => ['Safe 0' => 'safe', 'Porn 1' => 'pornographic', 'Suggestive 2' => 'suggestive', 'Erotica 3' => 'erotica'],
        2 => ['Safe 4' => 'safe', 'Erotica 5' => 'erotica'],
    ];
    $position = 0;

    foreach ($saved as $batch => $series) {
        foreach ($series as $title => $rating) {
            $manga = Covers::manga(sprintf('00000000-0000-4000-8000-%012d', $position), title: $title, rating: $rating);
            MangaListEntry::query()->insert(['list_key' => $key, 'batch' => $batch, 'position' => $position++, 'manga_id' => $manga->id, 'fetched_at' => Carbon::now()]);
        }
    }

    $screen = Native::test(Search::class)->call('searchFor', 'one punch');
    $texts = searchTexts($screen->tree());
    $at = fn (string $label) => array_search($label, $texts, true);

    expect(tileTitles($screen->tree()))->toBe(['Safe 0', 'Suggestive 2', 'Safe 4', 'Porn 1', 'Erotica 3', 'Erotica 5'])
        ->and($at('Rated content'))->toBeGreaterThan($at('Safe 4'))->toBeLessThan($at('Porn 1'))
        ->and(searchNodes($screen->tree(), 'divider'))->toHaveCount(1)
        // 1 grid row + the title row + 1 grid row.
        ->and(searchNodes($screen->tree(), 'virtual_list')[0]['props'])->toMatchArray(['count' => 3])
        ->and(Covers::requested($this->async))->toBe(array_map(
            fn (int $i) => sprintf('00000000-0000-4000-8000-%012d', $i),
            [0, 2, 4, 1, 3, 5],
        ));
});

it('keeps one grid in list order when the settings leave rated content out', function () {
    $key = app(MangaRepository::class)->listKey('one punch');
    $position = 0;

    foreach (['Safe 0' => 'safe', 'Erotica 1' => 'erotica', 'Safe 2' => 'safe'] as $title => $rating) {
        $manga = Covers::manga(sprintf('00000000-0000-4000-8000-%012d', $position), title: $title, rating: $rating);
        MangaListEntry::query()->insert(['list_key' => $key, 'batch' => 1, 'position' => $position++, 'manga_id' => $manga->id, 'fetched_at' => Carbon::now()]);
    }

    $screen = Native::test(Search::class)->call('searchFor', 'one punch');

    expect(tileTitles($screen->tree()))->toBe(['Safe 0', 'Erotica 1', 'Safe 2'])
        ->and(searchTexts($screen->tree()))->not->toContain('Rated content')
        ->and(searchNodes($screen->tree(), 'divider'))->toBe([])
        ->and(searchNodes($screen->tree(), 'virtual_list')[0]['props'])->toMatchArray(['count' => 1]);
});

it('shows no rated content title when the results have none', function () {
    $screen = Native::test(Search::class)->call('searchFor', 'one punch');

    expect(tileTitles($screen->tree()))->toHaveCount(10)
        ->and(searchTexts($screen->tree()))->not->toContain('Rated content')
        ->and(searchNodes($screen->tree(), 'divider'))->toBe([]);
});

it('starts a new search from the top, knowing nothing about more', function () {
    // Cooking saved earlier and still fresh: opening it asks nothing.
    app(MangaRepository::class)->refreshSearch('', [COOKING_TAG]);

    $screen = Native::test(Search::class)->call('searchFor', 'one punch')->call('windowChanged', 2, 5);
    expect($screen->get('more'))->toBeFalse();

    $screen->call('searchFor', '')
        ->toggle("openTag('".COOKING_TAG."')", true)
        ->assertSet('virtualWindowFrom', 0)
        ->assertSet('virtualWindowTo', 14);

    expect($screen->get('more'))->toBeNull()
        ->and(asked($this->asked, 'includedTags[]='.COOKING_TAG))->toBe(1);
});

it("drops the old search's status line when the search changes", function () {
    $screen = Native::test(Search::class);
    ($this->fake)(fn () => throw new ConnectionException('cURL error 6'));

    $screen->call('searchFor', 'one punch')->assertSee('Offline, showing saved data')
        ->call('searchFor', '')->assertDontSee('Offline')->assertSee('Genre');
});

it('keeps searching, not "Nothing saved", while The provider is busy', function () {
    ($this->fake)(fn (Request $request) => str_contains($request->url(), 'title=') ? throw busy() : null);

    $screen = Native::test(Search::class);
    $collisions = Skeletons::keyCollisions(fn () => $screen->call('searchFor', 'one punch'));
    $screen->assertSee('The provider is busy, will update shortly')
        ->assertDontSee('Nothing saved for this search');

    expect($collisions)->toBe([])
        ->and(searchNodes($screen->tree(), 'activity_indicator'))->toBe([])
        ->and(Skeletons::tiles($screen->tree()))->toBe(3 * Search::COLUMNS)
        ->and(array_unique(array_column(Skeletons::all($screen->tree()), 'id')))->toHaveCount(3 * Search::COLUMNS * 3);
});

it('says nothing is saved for this search and offers Retry when offline (spec §13)', function () {
    ($this->fake)(fn () => throw new ConnectionException('cURL error 6'));

    $screen = Native::test(Search::class)
        ->call('searchFor', 'one punch')
        ->assertSee('Offline, showing saved data')
        ->assertSee('Nothing saved for this search')
        ->assertSee('Retry');

    ($this->fake)();
    $screen->tap('Retry')->assertDontSee('Nothing saved for this search')->assertDontSee('Offline');
    expect(tileTitles($screen->tree()))->toHaveCount(10);
});

it('says MangaDex is having trouble, without Retry, while a search that met a 503 retries on its own', function () {
    Exceptions::fake();
    ($this->fake)(fn (Request $request) => str_contains($request->url(), 'title=') ? Http::response('No server is available to handle this request', 503) : null);

    $screen = Native::test(Search::class)
        ->call('searchFor', 'one punch')
        ->assertSee('The provider is having trouble, retrying')
        ->assertSee('Retrying on its own.')
        ->assertDontSee('Nothing saved for this search');
    expect(searchNodes($screen->tree(), 'button'))->toBe([]);
    Exceptions::assertNothingReported();

    ($this->fake)();
    Carbon::setTestNow(Carbon::now()->addSeconds(15));
    $screen->call('checkWaitingRefreshes')->assertDontSee('The provider is having trouble');
    expect(tileTitles($screen->tree()))->toHaveCount(10);
});

it('still says nothing is saved for this search and offers Retry when MangaDex rejects it', function () {
    Exceptions::fake();
    ($this->fake)(fn (Request $request) => str_contains($request->url(), 'title=')
        ? Http::response(['result' => 'error', 'errors' => [['status' => 400, 'title' => 'Bad Request', 'detail' => 'bad']]], 400)
        : null);

    $screen = Native::test(Search::class)
        ->call('searchFor', 'one punch')
        ->assertSee('Something went wrong')
        ->assertSee('Nothing saved for this search')
        ->assertDontSee('Retrying on its own.');
    expect(searchNodes($screen->tree(), 'button'))->toHaveCount(1);
});

it('says MangaDex is having trouble, without Retry, while a tag list that met a 503 retries on its own', function () {
    ($this->fake)(fn (Request $request) => str_contains($request->url(), '/manga/tag') ? Http::response('No server is available to handle this request', 503) : null);

    $screen = Native::test(Search::class)
        ->assertSee('Retrying on its own.')
        ->assertDontSee('No tags saved yet');
    expect(searchNodes($screen->tree(), 'button'))->toBe([]);
});

it('still says no tags are saved and offers Retry when MangaDex rejects the tag list', function () {
    Exceptions::fake();
    ($this->fake)(fn (Request $request) => str_contains($request->url(), '/manga/tag') ? Http::response(['result' => 'error', 'errors' => []], 400) : null);

    $screen = Native::test(Search::class)
        ->assertSee('No tags saved yet')
        ->assertDontSee('Retrying on its own.');
    expect(searchNodes($screen->tree(), 'button'))->toHaveCount(1);
});

it("forgets the old search's retry when the search changes", function () {
    ($this->fake)(fn (Request $request) => str_contains($request->url(), 'title=') ? Http::response('No server is available to handle this request', 503) : null);

    $screen = Native::test(Search::class)->call('searchFor', 'one punch');
    expect($screen->get('retryDueAt'))->toHaveCount(1);

    $screen->call('searchFor', '')->assertSet('retryDueAt', []);
});

it('says so when MangaDex finds nothing', function () {
    ($this->fake)(fn (Request $request) => str_contains($request->url(), 'title=')
        ? Http::response(['result' => 'ok', 'response' => 'collection', 'data' => [], 'limit' => 10, 'offset' => 0, 'total' => 0])
        : null);

    $screen = Native::test(Search::class)
        ->call('searchFor', 'zzzz nothing')
        ->assertSee('No series found')
        ->assertDontSee('Nothing saved for this search');

    expect($screen->get('more'))->toBeFalse();
});

it('opens a result in the Search tab', function () {
    $screen = Native::visit('/search')->call('searchFor', 'one punch');
    $first = app(MangaRepository::class)->search('one punch')->data->first();

    $screen->tap(tileTitles($screen->tree())[0])->assertNavigatedTo("/search/manga/{$first->id}");
});

it('fetches the first 12 covers of the rows in the window right away and queues the rest', function () {
    config(['readex.page_sizes.list' => 20]);
    Native::test(Search::class)->call('searchFor', 'one punch');
    $ids = app(MangaRepository::class)->search('one punch')->data->pluck('id')->all();

    $jobs = Queue::pushed(DownloadCovers::class)->values();
    expect(Covers::fetched($this->async))->toBe(array_slice($ids, 0, 12))
        ->and($jobs)->toHaveCount(1)
        ->and($jobs[0]->priority)->toBe(Priority::Prefetch)
        ->and($jobs[0]->mangaIds)->toBe(array_slice($ids, 12));
});

it('refreshes stale results and the tag list in the background', function () {
    $screen = Native::test(Search::class)->call('searchFor', 'one punch');
    Carbon::setTestNow(Carbon::now()->addHours(2));
    $this->asked = [];

    $screen->call('searchFor', '')->call('searchFor', 'one punch');

    expect(asked($this->asked, 'title=one punch'))->toBe(1)
        ->and(asked($this->asked, '/manga/tag'))->toBe(0);
});

it('renders Search without dropped Tailwind classes', function () {
    config(['app.debug' => true]);
    (fn () => self::$reportedUnsupportedByView = [])->bindTo(null, TailwindParser::class)();
    TailwindParser::clearCache();
    $dropped = [];
    Log::listen(function (MessageLogged $log) use (&$dropped) {
        if (str_contains($log->message, 'dropped unsupported')) {
            $dropped[] = $log->context;
        }
    });

    $screen = Native::test(Search::class)->assertSee('Genre')->call('searchFor', 'one punch');
    expect(tileTitles($screen->tree()))->not->toBeEmpty();
    $screen->call('searchFor', '')->toggle("openTag('".COOKING_TAG."')", true)->assertSee('Cooking');
    ($this->fake)(fn () => throw new ConnectionException('cURL error 6'));
    $screen->call('searchFor', 'nothing saved')->assertSee('Nothing saved for this search');

    expect($dropped)->toBe([]);
});
