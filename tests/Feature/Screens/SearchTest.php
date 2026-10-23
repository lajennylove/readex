<?php

use App\Jobs\DownloadCovers;
use App\Jobs\RefreshRepository;
use App\MangaDex\Exceptions\RequestDeferred;
use App\MangaDex\RateLimit\Busy;
use App\MangaDex\RateLimit\BusyReason;
use App\MangaDex\RateLimit\Priority;
use App\Models\MangaListEntry;
use App\NativeComponents\Search;
use App\Repositories\MangaRepository;
use App\Repositories\SettingsRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Native\Mobile\AsyncTask;
use Native\Mobile\Edge\TailwindParser;
use Native\Mobile\Testing\Native;
use Tests\Support\Covers;
use Tests\Support\MangaDexFixtures;

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
            $this->asked[] = rawurldecode($request->url());

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
        ->assertSee('MangaDex is busy, will update shortly');

    expect(asked($this->asked, 'offset=10'))->toBe(1);
    Queue::assertPushed(RefreshRepository::class, 1);

    ($this->fake)();
    Queue::pushed(RefreshRepository::class)->first()->handle();
    $screen->firePolls()->assertDontSee('MangaDex is busy');

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
        ->and(Queue::pushed(DownloadCovers::class)->flatMap->mangaIds->all())->toBe(array_slice($ids, 0, 45));

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

it('keeps searching, not "Nothing saved", while MangaDex is busy', function () {
    ($this->fake)(fn (Request $request) => str_contains($request->url(), 'title=') ? throw busy() : null);

    $screen = Native::test(Search::class)
        ->call('searchFor', 'one punch')
        ->assertSee('MangaDex is busy, will update shortly')
        ->assertDontSee('Nothing saved for this search');

    expect(searchNodes($screen->tree(), 'activity_indicator'))->toHaveCount(1);
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

it('queues the covers of the rows in the window, the first 12 interactive', function () {
    config(['readex.page_sizes.list' => 20]);
    Native::test(Search::class)->call('searchFor', 'one punch');
    $ids = app(MangaRepository::class)->search('one punch')->data->pluck('id')->all();

    $jobs = Queue::pushed(DownloadCovers::class)->values();
    expect($jobs[0]->priority)->toBe(Priority::Interactive)
        ->and($jobs[0]->mangaIds)->toBe(array_slice($ids, 0, 12))
        ->and($jobs[1]->priority)->toBe(Priority::Prefetch)
        ->and($jobs[1]->mangaIds)->toBe(array_slice($ids, 12));
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
