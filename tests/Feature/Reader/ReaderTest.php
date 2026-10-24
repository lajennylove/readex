<?php

use App\Jobs\DownloadChapter;
use App\Models\Collection as LibraryEntry;
use App\Models\ReadingProgress;
use App\Models\StoredChapter;
use App\NativeComponents\MangaDetail;
use App\NativeComponents\Reader;
use App\Pages\FetchPages;
use App\Pages\LoadState;
use App\Pages\PageFetcher;
use App\Repositories\LibraryRepository;
use App\Storage\PageStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Native\Mobile\AsyncTask;
use Native\Mobile\Edge\Element;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Testing\Native;
use Tests\Support\Covers;
use Tests\Support\Pages;
use Tests\Support\Skeletons;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 02:30:00', 'UTC'));
    Sleep::fake(syncWithCarbon: true);
    Covers::useTempCaches();
    $this->async = AsyncTask::fake();
    Queue::fake();
});

afterEach(fn () => AsyncTask::clearFake());

/** Rows the list shows, in order: header / end texts, "Page N" for an image, the text of a placeholder. */
function readerRows(array $tree): array
{
    $list = readerFind($tree, 'virtual_list');

    return $list === null ? [] : array_map(fn (array $row) => match (true) {
        $row['type'] === 'image' => $row['props']['alt'],
        $row['type'] === 'stack', Skeletons::all($row) !== [] => 'skeleton',
        default => implode(' | ', readerTexts($row)),
    }, $list['children'] ?? []);
}

function readerFind(array $node, string $type): ?array
{
    if (($node['type'] ?? null) === $type) {
        return $node;
    }

    foreach ($node['children'] ?? [] as $child) {
        if ($found = readerFind($child, $type)) {
            return $found;
        }
    }

    return null;
}

/** @return list<string> */
function readerTexts(array $node): array
{
    $texts = match ($node['type'] ?? null) {
        'text' => [$node['props']['text']],
        'button' => ['['.($node['props']['label'] ?? '').']'],
        default => [],
    };

    foreach ($node['children'] ?? [] as $child) {
        $texts = [...$texts, ...readerTexts($child)];
    }

    return $texts;
}

/** Every page of `$id` on the phone, as if read yesterday. */
function readerStored(string $id, int $count): void
{
    $store = app(PageStore::class);
    $store->begin(Pages::chapter($id, ['pages' => $count]), 'data-saver', $count);

    foreach (range(0, $count - 1) as $i) {
        $store->put($id, $i, Pages::image());
    }
}

function readerOffline(): void
{
    Http::swap(new Factory(app('events')));
    Http::fake(fn () => throw new ConnectionException('No route to host'));
}

it('reopens a chapter left on page 12 of 43 on page 12, with a Pages 1–11 row above it (Review Focus 1)', function () {
    Pages::fake(count: 43);
    Pages::chapter('c1', ['pages' => 43]);
    app(LibraryRepository::class)->saveProgress('c1', 11, 43);

    $screen = Native::visit('/home/read/c1')->assertScreen(Reader::class);

    expect(readerRows($screen->tree()))->toBe(['Ch. 12 · The Witch | Scanlation: Blackhole Scan | Pages 1–11', 'Page 12', 'Page 13'])
        ->and(readerFind($screen->tree(), 'virtual_list')['props']['count'])->toBe(1 + 32 + 1)
        ->and(Pages::requested())->toBe(array_map(fn (int $n) => "a.mangadex.network/data-saver/h1/p{$n}.jpg", [12, 13, 14, 15, 16]));
    $screen->assertNavTitle('Ch. 12 · 12 / 43');
});

it('reloads the chapter from page 1 from the Pages 1–N row', function () {
    Pages::fake(count: 43);
    Pages::chapter('c1', ['pages' => 43]);
    app(LibraryRepository::class)->saveProgress('c1', 11, 43);

    $screen = Native::visit('/home/read/c1')->tap('Pages 1–11');

    expect(readerRows($screen->tree()))->toBe(['Ch. 12 · The Witch | Scanlation: Blackhole Scan', 'Page 1', 'Page 2'])
        ->and(readerFind($screen->tree(), 'virtual_list')['props']['count'])->toBe(45)
        ->and(app(LibraryRepository::class)->progressFor('c1')->page)->toBe(11);
    $screen->assertNavTitle('Ch. 12 · 1 / 43');
});

it('opens a finished chapter from page 1', function () {
    Pages::fake(count: 5);
    Pages::chapter('c1', ['pages' => 5]);
    app(LibraryRepository::class)->saveProgress('c1', 4, 5);

    expect(readerRows(Native::visit('/home/read/c1')->tree())[1])->toBe('Page 1');
});

it('opens from page 1 when the chapter turned out shorter than the saved page', function () {
    Pages::fake(count: 5);
    Pages::chapter('c1', ['pages' => 30]);
    ReadingProgress::query()->create(['chapter_id' => 'c1', 'manga_id' => 'm1', 'page' => 20, 'page_count' => 30, 'updated_at' => now()]);

    $screen = Native::visit('/home/read/c1');

    expect(readerRows($screen->tree()))->toBe(['Ch. 12 · The Witch | Scanlation: Blackhole Scan', 'Page 1', 'Page 2']);
    $screen->assertNavTitle('Ch. 12 · 1 / 5');
});

it('reads a chapter read yesterday in Airplane mode: every page from the phone, no network, no offline text (Review Focus 2)', function () {
    readerStored('c1', 6);
    readerOffline();

    $screen = Native::visit('/home/read/c1')->call('windowChanged', 1, 6);

    expect(readerRows($screen->tree()))->toBe(['Page 1', 'Page 2', 'Page 3', 'Page 4', 'Page 5', 'Page 6'])
        ->and(Http::recorded())->toHaveCount(0)
        ->and(collect(readerTexts($screen->tree()))->contains(fn ($t) => str_contains($t, 'connection')))->toBeFalse();
    $this->async->assertNotDispatched(FetchPages::class);
});

it('shows Needs internet for a chapter never opened and leaves its progress alone; Retry loads it once online (Review Focus 2)', function () {
    Pages::chapter('c1', ['pages' => 3]);
    readerOffline();

    $screen = Native::visit('/home/read/c1');

    expect(readerTexts($screen->tree()))->toContain('Needs internet')->toContain('[Retry]')
        ->and(ReadingProgress::query()->count())->toBe(0)
        ->and(LibraryEntry::query()->count())->toBe(0);

    $screen->pressBack()->assertWentBack();
    $screen->instance()->unmount();

    expect(ReadingProgress::query()->count())->toBe(0);

    $screen = Native::visit('/home/read/c1');
    Http::swap(new Factory(app('events')));
    Pages::fake(count: 3);
    $screen->tap('Retry');

    expect(readerRows($screen->tree()))->toBe(['Ch. 12 · The Witch | Scanlation: Blackhole Scan', 'Page 1', 'Page 2']);
});

it('keeps a missing page waiting while offline and asks again every 15 s unless the phone has no connection', function () {
    $store = app(PageStore::class);
    $store->begin(Pages::chapter('c1', ['pages' => 4]), 'data-saver', 4);
    $store->put('c1', 0, Pages::image());
    readerOffline();

    $screen = Native::visit('/home/read/c1');

    expect(readerRows($screen->tree()))->toBe(['Ch. 12 · The Witch | Scanlation: Blackhole Scan', 'Page 1', 'Page 2 · Waiting for a connection'])
        ->and(app(PageFetcher::class)->state('c1'))->toBe(LoadState::Offline);

    $this->travel(10)->seconds();
    $screen->firePoll('checkPages');
    $this->async->assertDispatchedTimes(1);

    Native::fakeBridge()->respondTo('Network.Status', ['connected' => false]);
    $this->travel(6)->seconds();
    $screen->firePoll('checkPages');
    $this->async->assertDispatchedTimes(1);

    Native::fakeBridge()->respondTo('Network.Status', ['connected' => true]);
    Http::swap(new Factory(app('events')));
    Pages::fake(count: 4);
    $this->travel(16)->seconds();
    $screen->firePoll('checkPages');

    $this->async->assertDispatchedTimes(2);
    expect(readerRows($screen->tree()))->toBe(['Ch. 12 · The Witch | Scanlation: Blackhole Scan', 'Page 1', 'Page 2']);
});

it('says MangaDex is having trouble, not Not available, when the lookup meets a 503, and asks again every 15 s unless the phone has no connection', function () {
    Http::fake(['https://api.mangadex.org/at-home/server/*' => Http::response(['result' => 'error', 'errors' => [['status' => 503]]], 503)]);
    Pages::chapter('c1', ['pages' => 3]);

    $screen = Native::visit('/home/read/c1')->assertNavBarVisible()->assertNavTitle('Ch. 12 · 1 / 3');

    expect(readerTexts($screen->tree()))->toContain('MangaDex is having trouble')->toContain('Retrying on its own.')
        ->not->toContain('Not available')->not->toContain('Needs internet')->not->toContain('[Retry]')
        ->and(app(PageFetcher::class)->state('c1'))->toBe(LoadState::Retrying);

    $this->travel(10)->seconds();
    $screen->firePoll('checkPages');
    $this->async->assertDispatchedTimes(1);

    Native::fakeBridge()->respondTo('Network.Status', ['connected' => false]);
    $this->travel(6)->seconds();
    $screen->firePoll('checkPages');
    $this->async->assertDispatchedTimes(1);

    Native::fakeBridge()->respondTo('Network.Status', ['connected' => true]);
    Http::swap(new Factory(app('events')));
    Pages::fake(count: 3);
    $this->travel(16)->seconds();
    $screen->firePoll('checkPages');

    $this->async->assertDispatchedTimes(2);
    expect(readerRows($screen->tree()))->toBe(['Ch. 12 · The Witch | Scanlation: Blackhole Scan', 'Page 1', 'Page 2']);
});

it('keeps a missing page saying MangaDex is having trouble while the lookup meets a 503', function () {
    $store = app(PageStore::class);
    $store->begin(Pages::chapter('c1', ['pages' => 4]), 'data-saver', 4);
    $store->put('c1', 0, Pages::image());
    Http::fake(['https://api.mangadex.org/at-home/server/*' => Http::response('', 503)]);

    $screen = Native::visit('/home/read/c1');
    $placeholder = readerFind($screen->tree(), 'virtual_list')['children'][2];

    expect(readerRows($screen->tree()))->toBe(['Ch. 12 · The Witch | Scanlation: Blackhole Scan', 'Page 1', 'Page 2 · MangaDex is having trouble, retrying'])
        ->and($placeholder['id'])->toBe(Element::fnv1a32('/page-2'))
        ->and(app(PageFetcher::class)->failed('c1'))->toBe([]);
});

it('pulses a skeleton for a page on its way', function () {
    $store = app(PageStore::class);
    $store->begin(Pages::chapter('c1', ['pages' => 4]), 'data-saver', 4);
    Cache::put('readex.pages.running.c1', true, 60);

    expect(readerRows(Native::visit('/home/read/c1')->tree()))->toBe(['Ch. 12 · The Witch | Scanlation: Blackhole Scan', 'skeleton', 'skeleton']);
});

it('shows Tap to retry for a failed page and downloads it on tap (Review Focus 3)', function () {
    Pages::fake(count: 3, image: fn () => Http::response('nope', 500));
    Pages::chapter('c1', ['pages' => 3]);

    $screen = Native::visit('/home/read/c1');

    expect(readerRows($screen->tree())[1])->toBe("Page 1 didn't load · Tap to retry");

    Http::swap(new Factory(app('events')));
    Pages::fake(count: 3);
    $screen->call('retryPage', 0);

    expect(readerRows($screen->tree())[1])->toBe('Page 1')
        ->and(app(PageFetcher::class)->failed('c1'))->not->toContain(0);
});

it('follows the list window: counter, saved page, pages asked for', function () {
    Pages::fake(count: 12);
    Pages::chapter('c1', ['pages' => 12]);

    $screen = Native::visit('/home/read/c1')->call('windowChanged', 3, 7);

    $screen->assertNavTitle('Ch. 12 · 5 / 12');
    expect(app(LibraryRepository::class)->progressFor('c1'))->page->toBe(4)->finished_at->toBeNull()
        ->and(app(PageStore::class)->missing('c1'))->toBe([10, 11])
        ->and(LibraryEntry::query()->find('m1'))->not->toBeNull();
});

it('marks the chapter read once the end row enters the window', function () {
    Pages::fake(count: 10);
    Pages::chapter('c1', ['pages' => 10]);

    $screen = Native::visit('/home/read/c1')->call('windowChanged', 6, 11);

    $screen->assertNavTitle('Ch. 12 · 10 / 10');
    expect(app(LibraryRepository::class)->progressFor('c1'))->page->toBe(9)->finished_at->not->toBeNull();
});

it('saves the page and the open time when leaving', function () {
    Pages::fake(count: 3);
    Pages::chapter('c1', ['pages' => 3]);

    $screen = Native::visit('/home/read/c1');
    $this->travel(5)->minutes();
    $screen->pressBack()->assertWentBack();
    $screen->instance()->unmount();

    expect(app(LibraryRepository::class)->progressFor('c1')->page)->toBe(0)
        ->and(StoredChapter::query()->find('c1')->last_opened_at->toDateTimeString())->toBe('2026-10-24 02:35:00');
});

it('stamps the open time on open too, so a chapter read when iOS kills the app counts as recent', function () {
    readerStored('c1', 2);
    $this->travel(1)->day();

    Native::visit('/home/read/c1');

    expect(StoredChapter::query()->find('c1')->last_opened_at->toDateTimeString())->toBe('2026-10-25 02:30:00');
});

it('opens from page 1 as soon as pages land shorter than the saved page, before the next poll', function () {
    $chapter = Pages::chapter('c1', ['pages' => 30]);
    ReadingProgress::query()->create(['chapter_id' => 'c1', 'manga_id' => 'm1', 'page' => 20, 'page_count' => 30, 'updated_at' => now()]);
    Cache::put('readex.pages.running.c1', true, 60);

    $screen = Native::visit('/home/read/c1');
    $store = app(PageStore::class);
    $store->begin($chapter, 'data-saver', 5);
    foreach (range(0, 4) as $i) {
        $store->put('c1', $i, Pages::image());
    }
    $screen->call('toggleBar');

    expect(readerRows($screen->tree()))->toBe(['Ch. 12 · The Witch | Scanlation: Blackhole Scan', 'Page 1', 'Page 2']);
});

it('opens with the bar shown; a tap on a page hides it, another shows it', function () {
    readerStored('c1', 3);

    $screen = Native::visit('/home/read/c1')->assertNavBarVisible()->assertNavTitle('Ch. 12 · 1 / 3');
    expect(readerFind($screen->tree(), 'image'))->toHaveKey('on_press');

    $screen->press('toggleBar')->assertNavBarHidden()
        ->press('toggleBar')->assertNavBarVisible()->assertNavTitle('Ch. 12 · 1 / 3');
});

it('never hides the bar while loading, offline, unavailable, retrying, failed, external or missing, so back is always there', function (Closure $arrange, Closure $shows) {
    $arrange();

    $screen = Native::visit('/home/read/c1')->assertNavBarVisible();
    $shows($screen->tree());

    $screen->call('toggleBar')->assertNavBarVisible()
        ->call('toggleBar')->assertNavBarVisible()
        ->call('toggleBar')->assertNavBarVisible();
    $shows($screen->tree());
    expect(readerFind($screen->tree(), 'virtual_list'))->toBeNull();
})->with([
    'loading' => [function () {
        Pages::chapter('c1', ['pages' => 0]);
        Cache::put('readex.pages.running.c1', true, 60);
    }, fn (array $tree) => expect(array_column(Skeletons::pulsing($tree), 'id'))->toBe([Element::fnv1a32('/reader-loading-1'), Element::fnv1a32('/reader-loading-2')])],
    'offline' => [function () {
        Pages::chapter('c1', ['pages' => 3]);
        readerOffline();
    }, fn (array $tree) => expect(readerTexts($tree))->toContain('Needs internet')],
    'unavailable' => [function () {
        Http::fake(['https://api.mangadex.org/at-home/server/*' => Http::response(['result' => 'ok', 'baseUrl' => 'https://a.mangadex.network', 'chapter' => ['hash' => 'h', 'data' => [], 'dataSaver' => []]])]);
        Pages::chapter('c1', ['pages' => 3]);
    }, fn (array $tree) => expect(readerTexts($tree))->toContain('Not available')],
    'retrying' => [function () {
        Http::fake(['https://api.mangadex.org/at-home/server/*' => Http::response('', 503)]);
        Pages::chapter('c1', ['pages' => 3]);
    }, fn (array $tree) => expect(readerTexts($tree))->toContain('MangaDex is having trouble')],
    'failed' => [function () {
        Exceptions::fake();
        Http::fake(fn () => throw new RuntimeException('boom'));
        Pages::chapter('c1', ['pages' => 3]);
    }, fn (array $tree) => expect(readerTexts($tree))->toContain('Something went wrong')],
    'external' => [function () {
        Pages::fake();
        Pages::chapter('c1', ['pages' => 0, 'external_url' => 'https://mangaplus.shueisha.co.jp/viewer/12']);
    }, fn (array $tree) => expect(readerTexts($tree))->toContain('Opens on mangaplus.shueisha.co.jp')],
    'missing' => [fn () => null, fn (array $tree) => expect(readerTexts($tree))->toContain('Chapter not found')],
]);

it('goes back to the series from a chapter that is not available', function () {
    Http::fake(['https://api.mangadex.org/at-home/server/*' => Http::response(['result' => 'ok', 'baseUrl' => 'https://a.mangadex.network', 'chapter' => ['hash' => 'h', 'data' => [], 'dataSaver' => []]])]);
    Pages::chapter('c1', ['pages' => 3]);

    $reader = Native::visit('/home/manga/m1')->call('readChapter', 'c1')->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Read'])->assertNavigatedTo('/home/read/c1')
        ->follow()->assertScreen(Reader::class)->assertSee('Not available')->assertNavBarVisible();

    $reader->pressBack()->assertWentBack();
    $reader->goBack()->assertScreen(MangaDetail::class);
});

it('shows the bar again when the pages go away while it was hidden', function (LoadState $state, string $text) {
    readerStored('c1', 3);

    $screen = Native::visit('/home/read/c1')->press('toggleBar')->assertNavBarHidden();

    app(PageStore::class)->evict('c1');
    app(PageFetcher::class)->record('c1', $state);

    $screen->firePoll('checkPages')->assertSee($text)->assertNavBarVisible();
})->with([
    'offline' => [LoadState::Offline, 'Needs internet'],
    'unavailable' => [LoadState::Unavailable, 'Not available'],
    'retrying' => [LoadState::Retrying, 'MangaDex is having trouble'],
    'failed' => [LoadState::Failed, 'Something went wrong'],
]);

it('toggles the bar from a page still on its way, its skeleton keys unchanged', function () {
    $store = app(PageStore::class);
    $store->begin(Pages::chapter('c1', ['pages' => 4]), 'data-saver', 4);
    Cache::put('readex.pages.running.c1', true, 60);

    $collisions = Skeletons::keyCollisions(fn () => $this->screen = Native::visit('/home/read/c1'));
    $screen = $this->screen->assertNavBarVisible();
    $rows = array_slice(readerFind($screen->tree(), 'virtual_list')['children'], 1);
    $expressions = (fn () => $this->nativeCallbacks->expressions())->call($screen->instance());

    expect($collisions)->toBe([])
        ->and(readerRows($screen->tree()))->toBe(['Ch. 12 · The Witch | Scanlation: Blackhole Scan', 'skeleton', 'skeleton'])
        ->and(array_map(fn (array $row) => array_search($row['on_press'] ?? null, $expressions, true), $rows))->toBe(['toggleBar', 'toggleBar'])
        ->and(array_map(fn (array $row) => $row['props']['a11y_label'] ?? null, $rows))->toBe(['Page 1, loading', 'Page 2, loading'])
        ->and(array_column($rows, 'id'))->toBe([Element::fnv1a32('/page-1-tap'), Element::fnv1a32('/page-2-tap')])
        ->and(array_column(Skeletons::pulsing($screen->tree()), 'id'))->toBe([Element::fnv1a32('/page-1-tap/page-1'), Element::fnv1a32('/page-2-tap/page-2')])
        ->and(Skeletons::pulsing($rows[0])[0]['layout']['aspect_ratio'])->toEqual(5 / 7);

    $screen->press('toggleBar')->assertNavBarHidden()
        ->press('toggleBar')->assertNavBarVisible();
});

it('ends with the credit and Next: Ch. 13, which marks this one read and opens the next in the same tab', function () {
    readerStored('c1', 3);
    Pages::chapter('c2', ['number' => '13', 'pages' => 3]);

    $screen = Native::visit('/search/read/c1')->call('windowChanged', 0, 4);

    expect(readerRows($screen->tree())[4])->toBe('End of Ch. 12 | Scanlation: Blackhole Scan | [Next: Ch. 13]');

    $screen->tap('Next: Ch. 13')->assertReplacedWith('/search/read/c2');
    expect(app(LibraryRepository::class)->progressFor('c1')->finished_at)->not->toBeNull();
});

it('marks a one-page chapter read from Next, whose end row is there before any scroll', function () {
    readerStored('c1', 1);
    Pages::chapter('c2', ['number' => '13', 'pages' => 3]);

    Native::visit('/home/read/c1')->tap('Next: Ch. 13')->assertReplacedWith('/home/read/c2');

    expect(app(LibraryRepository::class)->progressFor('c1')?->finished_at)->not->toBeNull();
});

it("says You're caught up after the last chapter", function () {
    readerStored('c1', 2);

    $screen = Native::visit('/home/read/c1')->call('windowChanged', 0, 3);

    expect(readerRows($screen->tree())[3])->toBe("End of Ch. 12 | Scanlation: Blackhole Scan | You're caught up");
});

it('opens a licensed next chapter in the in-app browser', function () {
    readerStored('c1', 2);
    Pages::chapter('c2', ['number' => '13', 'pages' => 0, 'external_url' => 'https://mangaplus.shueisha.co.jp/viewer/13']);

    Native::visit('/home/read/c1')->call('windowChanged', 0, 3)->tap('Next: Ch. 13')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $p) => $p['url'] === 'https://mangaplus.shueisha.co.jp/viewer/13')
        ->assertNoNavigation();
});

it('sends a licensed chapter to the publisher and never asks @Home', function () {
    Pages::fake();
    Pages::chapter('c1', ['pages' => 0, 'external_url' => 'https://mangaplus.shueisha.co.jp/viewer/12']);

    $screen = Native::visit('/home/read/c1');

    expect(readerTexts($screen->tree()))->toContain('Opens on mangaplus.shueisha.co.jp')
        ->and(Pages::lookups())->toBe(0);
    $this->async->assertNotDispatched(FetchPages::class);
    $screen->tap('Open')->assertNativeCalled('Browser.OpenInApp', fn (array $p) => $p['url'] === 'https://mangaplus.shueisha.co.jp/viewer/12');
});

it('says Something went wrong with a Retry when the fetch fails unexpectedly, and Retry asks again', function () {
    Exceptions::fake();
    Http::fake(fn () => throw new RuntimeException('boom'));
    Pages::chapter('c1', ['pages' => 3]);

    $screen = Native::visit('/home/read/c1')->assertNavBarVisible()->assertNavTitle('Ch. 12 · 1 / 3');

    expect(readerTexts($screen->tree()))->toContain('Something went wrong')->toContain('[Retry]')
        ->and(Skeletons::pulsing($screen->tree()))->toBe([]);
    Exceptions::assertReported(RuntimeException::class);

    Http::swap(new Factory(app('events')));
    Pages::fake(count: 3);
    $screen->tap('Retry');

    expect(readerRows($screen->tree()))->toBe(['Ch. 12 · The Witch | Scanlation: Blackhole Scan', 'Page 1', 'Page 2']);
});

it('has a Retry on Not available, which asks MangaDex again', function () {
    Http::fake(['api.mangadex.org/*' => Http::response(['result' => 'error', 'errors' => [['status' => 404]]], 404)]);
    Pages::chapter('c1', ['pages' => 3]);

    $screen = Native::visit('/home/read/c1');

    expect(readerTexts($screen->tree()))->toContain('Not available')->toContain('[Retry]');

    Http::swap(new Factory(app('events')));
    Pages::fake(count: 3);
    $screen->tap('Retry');

    expect(readerRows($screen->tree()))->toBe(['Ch. 12 · The Witch | Scanlation: Blackhole Scan', 'Page 1', 'Page 2']);
});

it('says Not available when @Home has no pages', function () {
    Http::fake(['https://api.mangadex.org/at-home/server/*' => Http::response(['result' => 'ok', 'baseUrl' => 'https://a.mangadex.network', 'chapter' => ['hash' => 'h', 'data' => [], 'dataSaver' => []]])]);
    Pages::chapter('c1', ['pages' => 3]);

    expect(readerTexts(Native::visit('/home/read/c1')->tree()))->toContain('Not available');
    Queue::assertNotPushed(DownloadChapter::class);
});

it('sizes rows by the chapter\'s middle page shape, the config ratio before any page is stored', function () {
    $page = fn (int $w, int $h) => ['src' => 'x', 'width' => $w, 'height' => $h];

    expect(Reader::typicalRatio([]))->toBe([5, 7])
        ->and(Reader::typicalRatio([null, null]))->toBe([5, 7])
        ->and(Reader::typicalRatio([$page(800, 1200), null, $page(800, 4000), $page(800, 1100)]))->toBe([800, 1200]);

    readerStored('c1', 3);
    expect(readerFind(Native::visit('/home/read/c1')->tree(), 'virtual_list')['props']['estimated_row_height'])->toEqual(660);
});

it('lives under the tab it was opened from', function () {
    expect(Reader::uriFor('library', 'c1'))->toBe('/library/read/c1')
        ->and(Reader::uriFor('settings', 'c1'))->toBe('/home/read/c1');
});

it('names the screen even without a chapter', function () {
    Native::visit('/home/read/nope')->assertNavTitle('Reader')->assertSee('Chapter not found');
});
