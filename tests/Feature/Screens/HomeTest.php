<?php

use App\Adapters\Http\Exceptions\RequestDeferred;
use App\Adapters\Http\RateLimit\Busy;
use App\Adapters\Http\RateLimit\BusyReason;
use App\Adapters\Http\RateLimit\Priority;
use App\Jobs\DownloadCovers;
use App\Jobs\RefreshRepository;
use App\Models\Manga;
use App\NativeComponents\Home;
use App\NativeComponents\Library;
use App\NativeComponents\MangaDetail;
use App\NativeComponents\Reader;
use App\Repositories\HomeRepository;
use App\Repositories\LatestUpdate;
use App\Repositories\LibraryRepository;
use App\Repositories\SettingsRepository;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\ConnectionException;
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
use Tests\Support\Loading;

beforeEach(function () {
    Carbon::setTestNow('2026-10-24 12:00:00');
    Sleep::fake(syncWithCarbon: true);
    Covers::useTempCaches();
    // The recorded first-run calls are an es-la reader's.
    app(SettingsRepository::class)->setLanguage('es-la');
    $this->async = AsyncTask::fake();
    Queue::fake();
    $this->asked = [];
    Loading::fake(function (Request $request) {
        $this->asked[] = $request->url();

        return null;
    });
});

afterEach(fn () => AsyncTask::clearFake());

/** The three Home rows saved, as Setup leaves them. */
function fillHome(): void
{
    $home = app(HomeRepository::class);
    $home->refreshSeason();
    $home->refreshPopular();
    $home->refreshLatest();
}

/** Every text in the last render, in screen order. */
function homeTexts(array $node): array
{
    $texts = ($node['type'] ?? null) === 'text' ? [$node['props']['text']] : [];

    foreach ($node['children'] ?? [] as $child) {
        $texts = [...$texts, ...homeTexts($child)];
    }

    return $texts;
}

/** @return list<array> every node of this type */
function homeNodes(array $node, string $type): array
{
    $found = ($node['type'] ?? null) === $type ? [$node] : [];

    foreach ($node['children'] ?? [] as $child) {
        $found = [...$found, ...homeNodes($child, $type)];
    }

    return $found;
}

/** Child types of the tile row under a row label. */
function rowItems(array $node, string $label): ?array
{
    $children = $node['children'] ?? [];

    if (($children[0]['props']['text'] ?? null) === $label && ($children[1]['type'] ?? null) === 'scroll_view') {
        return array_column($children[1]['children'][0]['children'] ?? [], 'type');
    }

    foreach ($children as $child) {
        if (($items = rowItems($child, $label)) !== null) {
            return $items;
        }
    }

    return null;
}

/** Row labels, in screen order. */
function rowLabels(array $node): array
{
    $children = $node['children'] ?? [];

    if (($children[0]['type'] ?? null) === 'text' && ($children[1]['type'] ?? null) === 'scroll_view') {
        return [$children[0]['props']['text']];
    }

    return array_merge([], ...array_map('rowLabels', $children));
}

function rowMangas(): array
{
    $home = app(HomeRepository::class);

    return [
        'season' => $home->season()->data->values()->all(),
        'popular' => $home->popular()->data->values()->all(),
        'latest' => $home->latest()->data->map(fn (LatestUpdate $u) => $u->manga)->values()->all(),
    ];
}

it('fills every row on the first visit', function () {
    $screen = Native::test(Home::class);

    $rows = rowMangas();
    $chain = app(SettingsRepository::class)->chain();

    foreach ($rows as $mangas) {
        expect($mangas)->not->toBeEmpty();
        $screen->assertSee($chain->title($mangas[0]->title, $mangas[0]->alt_titles ?? []));
    }

    expect(homeNodes($screen->tree(), 'pressable'))->toHaveCount(array_sum(array_map('count', $rows)));
});

it('starts with This season, then Popular new and Latest updates, with no Continue reading row even for a started series (D37)', function () {
    fillHome();
    $rows = ['This season', 'Popular new titles', 'Latest updates'];
    expect(rowLabels(Native::test(Home::class)->tree()))->toBe($rows);

    $chapter = app(HomeRepository::class)->latest()->data->first()->chapter;
    app(LibraryRepository::class)->saveProgress($chapter->id, 3, 10);
    $tree = Native::test(Home::class)->tree();

    expect(rowLabels($tree))->toBe($rows)
        ->and(homeTexts($tree))->not->toContain('Continue reading')
        ->not->toContain(Home::chapterLabel($chapter).' · 40%');
});

it('badges the series that have the reading language (spec §10)', function () {
    fillHome();
    $chain = app(SettingsRepository::class)->chain();
    $badged = collect(rowMangas())->flatten()->filter(fn ($m) => $chain->badge($m->available_languages ?? []) !== null);

    $badges = homeNodes(Native::test(Home::class)->tree(), 'badge');

    expect($badged)->not->toBeEmpty()
        ->and($badged->count())->toBeLessThan(collect(rowMangas())->flatten()->count())
        ->and($badges)->toHaveCount($badged->count())
        ->and(array_unique(array_column(array_column($badges, 'props'), 'label')))->toBe(['ES']);
});

it('shows the chapter under each Latest updates tile', function () {
    fillHome();
    $updates = app(HomeRepository::class)->latest()->data;

    $texts = homeTexts(Native::test(Home::class)->tree());

    foreach ($updates->take(3) as $update) {
        expect($texts)->toContain(Home::chapterLabel($update->chapter));
    }
});

it('calls a chapter without a number a oneshot', function () {
    fillHome();
    $chapter = app(HomeRepository::class)->latest()->data->first()->chapter;
    $chapter->number = null;

    expect(Home::chapterLabel($chapter))->toBe('Oneshot');
});

it('refreshes only the row that went stale (Latest after 15 min)', function () {
    fillHome();
    Carbon::setTestNow(Carbon::now()->addMinutes(16));
    $this->asked = [];

    Native::test(Home::class);

    $asked = implode("\n", $this->asked);
    expect($asked)->toContain('api.mangadex.org/chapter')
        ->not->toContain('antsylich.github.io')
        ->not->toContain('createdAtSince');
});

it('keeps an empty row in place with placeholders while the others load (Review Focus 2)', function () {
    Loading::fake(fn (Request $request) => parse_url($request->url(), PHP_URL_HOST) === 'antsylich.github.io' ? Http::response('down', 503) : null);

    $screen = Native::test(Home::class)->assertSee('This season')->assertSee('The provider is having trouble, retrying');

    expect(rowItems($screen->tree(), 'This season'))->toBe(array_fill(0, 4, 'column'))
        ->and(rowItems($screen->tree(), 'Popular new titles'))->each->toBe('pressable')
        ->and(array_unique(array_map(fn (array $n) => $n['props']['axis'] ?? null, homeNodes($screen->tree(), 'scroll_view'))))->toBe(['horizontal']);
});

it('says nothing is saved and offers Retry when the first visit is offline (spec §13)', function () {
    Loading::fake(fn () => throw new ConnectionException('cURL error 6'));

    $screen = Native::test(Home::class)
        ->assertSee('Offline, showing saved data')
        ->assertSee('Nothing saved yet')
        ->assertSee('Retry')
        ->assertDontSee('This season');

    Loading::fake();
    $screen->tap('Retry')->assertDontSee('Nothing saved yet')->assertSee('This season');
    expect(rowMangas()['season'])->not->toBeEmpty();
});

it('says MangaDex is having trouble, without Retry, when the first visit meets a MangaDex outage', function () {
    Exceptions::fake();
    Loading::fake(fn (Request $request) => parse_url($request->url(), PHP_URL_HOST) === 'api.mangadex.org'
        ? Http::response('No server is available to handle this request', 503)
        : null);

    $screen = Native::test(Home::class)
        ->assertSee('The provider is having trouble, retrying')
        ->assertSee('Retrying on its own.')
        ->assertDontSee('Nothing saved yet')
        ->assertDontSee('This season');
    expect(homeNodes($screen->tree(), 'button'))->toBe([]);
    Exceptions::assertNothingReported();

    Loading::fake();
    Carbon::setTestNow(Carbon::now()->addSeconds(15));
    $screen->call('checkWaitingRefreshes')->assertDontSee('The provider is having trouble')->assertSee('This season');
    expect(rowMangas()['season'])->not->toBeEmpty();
});

it('still says nothing is saved and offers Retry when MangaDex rejects the first visit', function () {
    Exceptions::fake();
    Loading::fake(fn (Request $request) => parse_url($request->url(), PHP_URL_HOST) === 'api.mangadex.org'
        ? Http::response(['result' => 'error', 'errors' => [['status' => 400, 'title' => 'Bad Request', 'detail' => 'bad']]], 400)
        : null);

    $screen = Native::test(Home::class)
        ->assertSee('Something went wrong')
        ->assertSee('Nothing saved yet')
        ->assertDontSee('Retrying on its own.');
    expect(homeNodes($screen->tree(), 'button'))->toHaveCount(1);
});

it('waits for the background retry instead of offering Retry when The provider is busy', function () {
    Loading::fake(fn () => throw new RequestDeferred(new Busy(Carbon::now()->addMinute()->getTimestampMs() / 1000, BusyReason::Saturated)));

    Native::test(Home::class)
        ->assertSee('The provider is busy, will update shortly')
        ->assertDontSee('Nothing saved yet')
        ->assertSee('This season');

    Queue::assertPushed(RefreshRepository::class, 3);
});

it('shows empty rows, not "Nothing saved yet", when MangaDex has nothing', function () {
    Loading::fake(fn (Request $request) => match (true) {
        parse_url($request->url(), PHP_URL_HOST) === 'antsylich.github.io' => Http::response(['id' => 'list-1', 'name' => 'Fall 2026', 'manga_ids' => []]),
        parse_url($request->url(), PHP_URL_HOST) === 'api.mangadex.org' => Http::response(['result' => 'ok', 'response' => 'collection', 'data' => [], 'limit' => 20, 'offset' => 0, 'total' => 0]),
        default => null,
    });

    $screen = Native::test(Home::class)->assertDontSee('Nothing saved yet')->assertSee('Latest updates');

    expect(rowItems($screen->tree(), 'Latest updates'))->toBe(array_fill(0, 4, 'column'));
});

it('keeps the saved rows when offline', function () {
    fillHome();
    Carbon::setTestNow(Carbon::now()->addDays(2));
    Loading::fake(fn () => throw new ConnectionException('cURL error 6'));
    $first = rowMangas()['popular'][0];

    Native::test(Home::class)
        ->assertSee('Offline, showing saved data')
        ->assertSee(app(SettingsRepository::class)->chain()->title($first->title, $first->alt_titles ?? []))
        ->assertDontSee('Nothing saved yet');
});

it('refreshes every row on pull to refresh, fresh or not', function () {
    fillHome();
    $screen = Native::test(Home::class);
    // Past the client's 5 s shared-GET cache.
    Carbon::setTestNow(Carbon::now()->addSeconds(10));
    $this->asked = [];

    $screen->call('pullToRefresh');

    $asked = implode("\n", $this->asked);
    expect($asked)->toContain('antsylich.github.io')
        ->toContain('createdAtSince')
        ->toContain('api.mangadex.org/chapter')
        ->and(homeNodes($screen->tree(), 'refreshable')[0]['props'])->toHaveKey('on_refresh');
});

it('opens a tile in the Home tab', function () {
    fillHome();
    $first = rowMangas()['season'][0];
    $title = app(SettingsRepository::class)->chain()->title($first->title, $first->alt_titles ?? []);

    Native::test(Home::class)->tap($title)->assertNavigatedTo("/home/manga/{$first->id}");
});

it('opens every Home tile on the series; the Library placeholder opens a Continue reading tile on its series, which opens the chapter in the reader, a licensed one in the in-app browser, a gone one nowhere', function () {
    fillHome();
    $chapter = app(HomeRepository::class)->latest()->data->first()->chapter;
    app(LibraryRepository::class)->saveProgress($chapter->id, 4, 10);
    $caption = Home::chapterLabel($chapter).' · 50%';
    $other = collect(rowMangas()['season'])->first(fn ($m) => $m->id !== $chapter->manga_id);
    $title = app(SettingsRepository::class)->chain()->title($other->title, $other->alt_titles ?? []);

    Native::test(Home::class)->tap($title)->assertNavigatedTo("/home/manga/{$other->id}");
    Native::visit('/library')->tap($caption)->assertNavigatedTo("/library/manga/{$chapter->manga_id}")
        ->follow()->assertScreen(MangaDetail::class)->assertNavigatedTo("/library/read/{$chapter->id}");

    $chapter->update(['external_url' => 'https://mangaplus.shueisha.co.jp/viewer/1']);

    $library = Native::visit('/library')->tap($caption)
        ->assertNavigatedTo("/library/manga/{$chapter->manga_id}")
        ->assertNativeNotCalled('Browser.OpenInApp');
    expect($library->navigationIntent()->data)->toBe(['read' => $chapter->id]);

    $library->follow()->assertScreen(MangaDetail::class)
        ->assertNativeCalled('Browser.OpenInApp', fn (array $p) => $p['url'] === 'https://mangaplus.shueisha.co.jp/viewer/1')
        ->assertNoNavigation();

    Native::visit('/library')->call('openChapter', 'gone')->assertNoNavigation();
});

it('opens a Continue reading tile on top of its series after the settle pause, so back goes to the series, then the Library', function () {
    fillHome();
    config(['readex.reader.via_detail_settle_ms' => 357]);
    $settled = fn (CarbonInterval $d) => (int) $d->totalMilliseconds === 357;
    $chapter = app(HomeRepository::class)->latest()->data->first()->chapter;
    app(LibraryRepository::class)->saveProgress($chapter->id, 4, 10);
    $manga = Manga::query()->find($chapter->manga_id);
    $title = app(SettingsRepository::class)->chain()->title($manga->title, $manga->alt_titles ?? []);

    $library = Native::visit('/library')->tap(Home::chapterLabel($chapter).' · 50%')->assertNavigatedTo("/library/manga/{$chapter->manga_id}");
    expect($library->navigationIntent()->data)->toBe(['read' => $chapter->id]);
    Sleep::assertSlept($settled, 0);

    $detail = $library->follow()->assertScreen(MangaDetail::class)->assertNavigatedTo("/library/read/{$chapter->id}");
    Sleep::assertSlept($settled, 1);

    $detail = $detail->follow()->assertScreen(Reader::class)->assertSet('chapterId', $chapter->id)
        ->goBack()->assertScreen(MangaDetail::class)->assertSet('mangaId', $chapter->manga_id)->assertNoNavigation();
    expect($detail->instance()->navTitle())->toBe($title);
    Sleep::assertSlept($settled, 1);

    $detail->goBack()->assertScreen(Library::class)->assertNoNavigation();
});

it('fetches the first 12 covers right away and queues the rest, in screen order, without the started series in front', function () {
    fillHome();
    $rows = rowMangas();
    $started = collect(app(HomeRepository::class)->latest()->data)->first(fn (LatestUpdate $u) => $u->manga->id !== $rows['season'][0]->id);
    app(LibraryRepository::class)->saveProgress($started->chapter->id, 0, 10);
    $order = array_map(fn ($m) => $m->id, [...$rows['season'], ...$rows['popular'], ...$rows['latest']]);
    $continueFirst = [$started->manga->id, ...$order];

    Native::test(Home::class);

    $jobs = Queue::pushed(DownloadCovers::class)->values();
    expect(Covers::fetched($this->async))->toBe(array_values(array_unique(array_slice($order, 0, 12))))
        ->not->toBe(array_values(array_unique(array_slice($continueFirst, 0, 12))))
        ->and($jobs)->toHaveCount(1)
        ->and($jobs[0]->priority)->toBe(Priority::Prefetch);
});

it('keeps Continue reading on the Library placeholder until the Phase 6 grid, never on Home (choice 2, D37)', function () {
    fillHome();
    Native::test(Library::class)->assertSee('Your series will show up here')->assertDontSee('Continue reading');

    $chapter = app(HomeRepository::class)->latest()->data->first()->chapter;
    app(LibraryRepository::class)->saveProgress($chapter->id, 4, 10);

    Queue::fake();
    Native::test(Library::class)
        ->assertSee('Continue reading')
        ->assertSee(Home::chapterLabel($chapter).' · 50%')
        ->assertSee('Your series will show up here');

    expect(Covers::fetched($this->async))->toContain($chapter->manga_id);
    Native::test(Home::class)->assertSee('This season')->assertDontSee('Continue reading');
});

it('renders Home and Library without dropped Tailwind classes', function () {
    config(['app.debug' => true]);
    (fn () => self::$reportedUnsupportedByView = [])->bindTo(null, TailwindParser::class)();
    TailwindParser::clearCache();
    $dropped = [];
    Log::listen(function (MessageLogged $log) use (&$dropped) {
        if (str_contains($log->message, 'dropped unsupported')) {
            $dropped[] = $log->context;
        }
    });

    Loading::fake(fn () => throw new ConnectionException('cURL error 6'));
    Native::test(Home::class)->assertSee('Nothing saved yet');
    Loading::fake();
    fillHome();
    app(LibraryRepository::class)->saveProgress(app(HomeRepository::class)->latest()->data->first()->chapter->id, 0, 10);
    Native::test(Home::class)->assertSee('This season');
    Native::test(Library::class)->assertSee('Continue reading');

    expect($dropped)->toBe([]);
});
