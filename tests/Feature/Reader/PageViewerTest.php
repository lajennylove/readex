<?php

use App\Jobs\PrefetchNext;
use App\Models\ReadingProgress;
use App\Models\StoredChapter;
use App\NativeComponents\PageViewer;
use App\NativeComponents\Reader;
use App\Pages\FetchPages;
use App\Repositories\LibraryRepository;
use App\Storage\OpenChapter;
use App\Storage\PageStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Native\Mobile\AsyncTask;
use Native\Mobile\Edge\Element;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Tests\Support\Covers;
use Tests\Support\Pages;
use Tests\Support\Stored;

const VIEWER_OPENED_AT = '2026-10-24 02:30:00';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(VIEWER_OPENED_AT, 'UTC'));
    Sleep::fake(syncWithCarbon: true);
    $this->caches = Covers::useTempCaches();
    $this->async = AsyncTask::fake();
    Queue::fake();
});

afterEach(fn () => AsyncTask::clearFake());

/** Every page of `$id` on the phone (4×6 px, page 6 is 8×10 when there is one). */
function viewerStored(string $id, int $count): void
{
    $store = app(PageStore::class);
    $store->begin(Pages::chapter($id, ['pages' => $count]), 'data-saver', $count);

    foreach (range(0, $count - 1) as $i) {
        $store->put($id, $i, $i === 5 ? Pages::image(8, 10) : Pages::image());
    }
}

/** @return list<array> every node of `$type`, depth first */
function viewerAll(array $node, string $type): array
{
    $found = ($node['type'] ?? null) === $type ? [$node] : [];

    foreach ($node['children'] ?? [] as $child) {
        $found = [...$found, ...viewerAll($child, $type)];
    }

    return $found;
}

function viewerNode(array $tree, string $type): ?array
{
    return viewerAll($tree, $type)[0] ?? null;
}

/** The reader's page image with this alt. */
function viewerRowImage(array $tree, string $alt): ?array
{
    return collect(viewerAll($tree, 'image'))->first(fn (array $image) => ($image['props']['alt'] ?? null) === $alt);
}

/** @return list<string> */
function viewerTexts(array $tree): array
{
    return array_map(fn (array $text) => $text['props']['text'], viewerAll($tree, 'text'));
}

/** @return list<string> the shared-value handles (`__sv:N`) bound anywhere in the tree, sorted */
function viewerSharedIds(array $tree): array
{
    preg_match_all('/__sv:(\d+)/', json_encode($tree), $matches);
    $ids = array_values(array_unique($matches[0]));
    sort($ids, SORT_NATURAL);

    return $ids;
}

/** Double-tap the reader row showing `$alt` through its own `on_double_tap` callback. */
function viewerDoubleTap(TestableComponent $screen, string $alt): TestableComponent
{
    $id = viewerRowImage($screen->tree(), $alt)['props']['on_double_tap'] ?? null;
    expect($id)->toBeInt();

    $expressions = (fn () => $this->nativeCallbacks->expressions())->call($screen->instance());

    return $screen->press((string) array_search($id, $expressions, true));
}

it('opens the viewer on the page double-tapped, in the reader\'s tab, from a reader opened mid-chapter', function () {
    viewerStored('c1', 12);
    app(LibraryRepository::class)->saveProgress('c1', 4, 12);

    $reader = Native::visit('/search/read/c1');

    expect($reader->get('start'))->toBe(4);
    viewerDoubleTap($reader, 'Page 6')->assertNavigatedTo('/search/read/c1/page/6');

    $viewer = $reader->follow()->assertScreen(PageViewer::class);
    $src = app(PageStore::class)->pages('c1')[5]['src'];

    expect(viewerNode($viewer->tree(), 'image')['props']['src'])->toBe($src)
        ->and($viewer->get('tab'))->toBe('search');
    $viewer->assertNavTitle('Ch. 12 · 6 / 12');
});

it('keeps single tap on a page for the bar, double-tap on stored pages only', function () {
    viewerStored('c1', 3);

    $reader = Native::visit('/home/read/c1');
    $image = viewerRowImage($reader->tree(), 'Page 1');

    expect($image)->toHaveKey('on_press')
        ->and($image['props'])->toHaveKey('on_double_tap');
    $reader->call('toggleBar')->assertNavBarVisible()->assertNoNavigation();
});

it('shows the stored page full screen, black, bar hidden; a tap toggles the bar', function () {
    viewerStored('c1', 12);

    $viewer = Native::visit('/home/read/c1/page/6')->assertScreen(PageViewer::class)->assertNavBarHidden();
    $image = viewerNode($viewer->tree(), 'image');

    expect($image['props']['src'])->toBe(app(PageStore::class)->pages('c1')[5]['src'])
        ->and($image['props']['src'])->not->toStartWith('http')
        ->and($image['props']['alt'])->toBe('Page 6')
        ->and($image['layout']['aspect_ratio'] ?? $image['props']['aspect_ratio'] ?? null)->toEqual(0.8)
        ->and($image['id'])->toBe(Element::fnv1a32('/viewer-page'))
        ->and(json_encode($viewer->tree()))->toContain('#000000');

    $viewer->assertNavTitle('Ch. 12 · 6 / 12')
        ->press('toggleBar')->assertNavBarVisible()
        ->press('toggleBar')->assertNavBarHidden();
    $this->async->assertNotDispatched();
    Queue::assertNothingPushed();
    expect(Http::recorded())->toHaveCount(0);
});

it('never opens the viewer for a page that is waiting, failed or on its way', function () {
    $store = app(PageStore::class);
    $store->begin(Pages::chapter('c1', ['pages' => 4]), 'data-saver', 4);
    $store->put('c1', 0, Pages::image());
    Http::swap(new Factory(app('events')));
    Http::fake(fn () => throw new ConnectionException('No route to host'));

    $reader = Native::visit('/home/read/c1');
    $waiting = collect(viewerAll($reader->tree(), 'pressable'))->first(fn (array $row) => in_array('Page 2 · Waiting for a connection', viewerTexts($row), true));

    expect($waiting)->not->toBeNull()
        ->and($waiting['props'])->not->toHaveKey('on_double_tap')
        ->and(viewerRowImage($reader->tree(), 'Page 1')['props'])->toHaveKey('on_double_tap');
    $reader->call('openPage', 1)->assertNoNavigation();
    $reader->call('openPage', 7)->assertNoNavigation();
    $reader->call('openPage', -1)->assertNoNavigation();
});

it('refuses a double-tap that arrives after its page was purged', function () {
    viewerStored('c1', 3);

    $reader = Native::visit('/home/read/c1');
    unlink("{$this->caches}/chapters/c1/001.jpg");

    $reader->call('openPage', 0)->assertNoNavigation();
    $reader->call('openPage', 1)->assertNavigatedTo('/home/read/c1/page/2');
});

it('never opens the viewer for a failed page or a skeleton', function () {
    Pages::fake(count: 3, image: fn () => Http::response('nope', 500));
    Pages::chapter('c1', ['pages' => 3]);

    $reader = Native::visit('/home/read/c1');
    $failed = viewerAll($reader->tree(), 'pressable');

    expect($failed)->not->toBeEmpty()
        ->and(collect($failed)->filter(fn (array $row) => isset($row['props']['on_double_tap'])))->toBeEmpty();
    $reader->call('openPage', 0)->assertNoNavigation();

    $store = app(PageStore::class);
    $store->begin(Pages::chapter('c2', ['pages' => 4]), 'data-saver', 4);
    Cache::put('readex.pages.running.c2', true, 60);

    $reader = Native::visit('/home/read/c2');

    expect(json_encode($reader->tree()))->not->toContain('on_double_tap');
    $reader->call('openPage', 0)->assertNoNavigation();
});

it('comes back to the reader where it was: same page, window, bar, progress, open time, open chapter and prefetch (Review Focus 1)', function () {
    viewerStored('c1', 12);
    app(LibraryRepository::class)->saveProgress('c1', 4, 12);
    Stored::network(Stored::wifi());

    $reader = Native::visit('/home/read/c1')->call('windowChanged', 2, 6)->call('toggleBar')->firePoll('checkPages');
    Queue::assertPushed(PrefetchNext::class, 1);
    $savedAt = ReadingProgress::query()->find('c1')->updated_at->toDateTimeString();

    expect($reader->get('start'))->toBe(4)
        ->and($reader->get('page'))->toBe(7)
        ->and(app(LibraryRepository::class)->progressFor('c1')->page)->toBe(7);

    $this->travel(5)->minutes();
    $viewer = viewerDoubleTap($reader, 'Page 8')->follow()->assertScreen(PageViewer::class)->assertNavTitle('Ch. 12 · 8 / 12');
    $viewer->press('doubleTapped');

    $this->travel(5)->minutes();
    $back = $viewer->goBack()->assertScreen(Reader::class);

    expect($back)->toBe($reader)
        ->and($reader->get('start'))->toBe(4)
        ->and($reader->get('page'))->toBe(7)
        ->and($reader->get('virtualWindowFrom'))->toBe(2)
        ->and($reader->get('virtualWindowTo'))->toBe(6)
        ->and($reader->get('barShown'))->toBeTrue()
        ->and(ReadingProgress::query()->find('c1'))->page->toBe(7)
        ->and(ReadingProgress::query()->find('c1')->updated_at->toDateTimeString())->toBe($savedAt)
        ->and(StoredChapter::query()->find('c1')->last_opened_at->toDateTimeString())->toBe(VIEWER_OPENED_AT)
        ->and(viewerRowImage($reader->tree(), 'Page 8'))->not->toBeNull()
        ->and(app(OpenChapter::class)->id())->toBe('c1')
        ->and($reader->get('prefetched'))->toBeTrue();
    $reader->assertNavBarVisible()->assertNavTitle('Ch. 12 · 8 / 12');

    $reader->firePoll('checkPages');
    Queue::assertPushed(PrefetchNext::class, 1);
});

it('never names or clears the open chapter itself', function () {
    viewerStored('c1', 3);
    app(OpenChapter::class)->set('t5-other');

    $viewer = Native::visit('/home/read/c1/page/1')->press('doubleTapped')->press('toggleBar');
    expect(app(OpenChapter::class)->id())->toBe('t5-other');

    $viewer->instance()->unmount();
    expect(app(OpenChapter::class)->id())->toBe('t5-other');

    app(OpenChapter::class)->set('c1');
    Native::visit('/home/read/c1/page/2')->instance()->unmount();
    expect(app(OpenChapter::class)->id())->toBe('c1');
});

it('stores the pinch, clamps a drag to the current zoom and toggles 1× / 2.5× on double-tap', function () {
    viewerStored('c1', 3);

    // 4×6 page at 440 pt wide = 660 pt tall in an 820 pt viewer.
    $viewer = Native::visit('/home/read/c1/page/1')
        ->pinch('pinchEnded', 2.0)->assertSet('zoom', 2.0)
        ->dragEnd('dragEnded', 9999.0, -9999.0)->assertSet('x', 220.0)->assertSet('y', -250.0)
        ->pinch('pinchEnded', 1.5)->assertSet('x', 110.0)->assertSet('y', -85.0);

    $viewer->pinch('pinchEnded', 1.0)->assertSet('zoom', 1.0)->assertSet('x', 0.0)->assertSet('y', 0.0);

    $viewer->press('doubleTapped')->assertSet('zoom', PageViewer::DOUBLE_TAP_ZOOM)
        ->dragEnd('dragEnded', 100.0, 50.0)->assertSet('x', 100.0)->assertSet('y', 50.0)
        ->press('doubleTapped')->assertSet('zoom', 1.0)->assertSet('x', 0.0)->assertSet('y', 0.0);

    $viewer->pinch('pinchEnded', 9.0)->assertSet('zoom', PageViewer::MAX_ZOOM);
});

it('keeps a wide page that still fits the viewer\'s height from moving up or down', function () {
    $store = app(PageStore::class);
    $store->begin(Pages::chapter('c1', ['pages' => 1]), 'data-saver', 1);
    $store->put('c1', 0, Pages::image(6, 4));

    // 6×4 at 440 pt = 293 pt tall, 587 pt at 2× in an 820 pt viewer.
    Native::visit('/home/read/c1/page/1')->pinch('pinchEnded', 2.0)
        ->dragEnd('dragEnded', -500.0, 500.0)->assertSet('x', -220.0)->assertSet('y', 0.0);
});

it('recentres a page taller than the viewer when it goes back to 1×', function () {
    $store = app(PageStore::class);
    $store->begin(Pages::chapter('c1', ['pages' => 1]), 'data-saver', 1);
    $store->put('c1', 0, Pages::image(4, 10));

    // 4×10 at 440 pt = 1100 pt tall: taller than the 820 pt viewer even at 1×.
    $viewer = Native::visit('/home/read/c1/page/1')->press('doubleTapped')
        ->dragEnd('dragEnded', 50.0, 100.0)->assertSet('y', 100.0)
        ->press('doubleTapped')->assertSet('zoom', 1.0)->assertSet('x', 0.0)->assertSet('y', 0.0);

    $viewer->pinch('pinchEnded', 2.0)->dragEnd('dragEnded', 0.0, -120.0)
        ->pinch('pinchEnded', 1.0)->assertSet('y', 0.0);
});

it('pushes its zoom to the shared value on every render', function () {
    viewerStored('c1', 3);

    $viewer = Native::visit('/home/read/c1/page/1')->press('doubleTapped');

    expect(viewerNode($viewer->tree(), 'image')['props']['scale'])->toEqual(PageViewer::DOUBLE_TAP_ZOOM);
    $viewer->pinch('pinchEnded', 3.0);
    expect(viewerNode($viewer->tree(), 'image')['props']['scale'])->toEqual(3.0);
});

it('binds pan only while zoomed, clamped live to the current zoom\'s bounds', function () {
    viewerStored('c1', 3);

    $viewer = Native::visit('/home/read/c1/page/1');
    $area = viewerNode($viewer->tree(), 'gesture_area');

    expect($area['props'])->toHaveKey('on_pinch_end')->not->toHaveKey('on_drag_end')
        ->and(json_encode($area['props']))->not->toContain('pan')
        ->and(json_encode(viewerNode($viewer->tree(), 'image')['props']))->not->toContain('translate');

    $viewer->press('doubleTapped');
    $area = viewerNode($viewer->tree(), 'gesture_area');
    $image = json_encode(viewerNode($viewer->tree(), 'image')['props']);

    expect(viewerNode($viewer->tree(), 'image')['id'])->toBe(Element::fnv1a32('/viewer-page'));

    expect($area['props'])->toHaveKey('on_drag_end')
        ->and(json_encode($area['props']))->toContain('pan')
        ->and($image)->toContain('clamp:-330,330')
        ->and($image)->toContain('clamp:-415,415');
});

it('keeps its shared values across renders, a new viewer gets its own', function () {
    viewerStored('c1', 3);

    $viewer = Native::visit('/home/read/c1/page/1')->press('doubleTapped');
    $ids = viewerSharedIds($viewer->tree());

    expect($ids)->toHaveCount(3);

    $viewer->dragEnd('dragEnded', 10.0, 10.0)->pinch('pinchEnded', 3.0)->press('toggleBar');
    expect(viewerSharedIds($viewer->tree()))->toBe($ids);

    $viewer->press('doubleTapped');
    expect(viewerSharedIds($viewer->tree()))->toHaveCount(1)
        ->and($ids)->toContain(viewerSharedIds($viewer->tree())[0]);

    $other = Native::visit('/home/read/c1/page/2')->press('doubleTapped');
    expect(array_intersect(viewerSharedIds($other->tree()), $ids))->toBeEmpty()
        ->and($other->get('zoom'))->toBe(PageViewer::DOUBLE_TAP_ZOOM);
});

it('says a page isn\'t on the phone with the bar shown, and never goes online', function (Closure $arrange, string $uri, string $message, string $title) {
    $arrange($this->caches);

    $viewer = Native::visit($uri)->assertScreen(PageViewer::class)->assertNavBarVisible()->assertNavTitle($title);

    expect(viewerTexts($viewer->tree()))->toContain($message)
        ->and(viewerNode($viewer->tree(), 'image'))->toBeNull()
        ->and(viewerNode($viewer->tree(), 'gesture_area'))->toBeNull()
        ->and(Http::recorded())->toHaveCount(0);
    $this->async->assertNotDispatched(FetchPages::class);
    $this->async->assertNotDispatched();
    Queue::assertNothingPushed();

    $viewer->pressBack()->assertWentBack();
})->with([
    'purged file' => [function (string $caches) {
        viewerStored('c1', 3);
        unlink("{$caches}/chapters/c1/002.jpg");
    }, '/home/read/c1/page/2', "Page 2 isn't on this phone yet", 'Ch. 12 · 2 / 3'],
    'never fetched' => [fn () => Pages::chapter('c1', ['pages' => 3]), '/home/read/c1/page/2', "Page 2 isn't on this phone yet", 'Ch. 12 · 2 / 3'],
    'past the last page' => [fn () => viewerStored('c1', 3), '/home/read/c1/page/7', "Page 7 isn't on this phone yet", 'Ch. 12'],
    'unknown chapter' => [fn () => null, '/home/read/nope/page/1', "Page 1 isn't on this phone yet", 'Page 1'],
]);

it('lives under the tab it was opened from', function () {
    viewerStored('c1', 3);

    expect(PageViewer::uriFor('library', 'c1', 5))->toBe('/library/read/c1/page/5')
        ->and(PageViewer::uriFor('search', 'c1', 1))->toBe('/search/read/c1/page/1')
        ->and(PageViewer::uriFor('settings', 'c1', 2))->toBe('/home/read/c1/page/2')
        ->and(Native::visit('/library/read/c1/page/1')->assertScreen(PageViewer::class)->get('tab'))->toBe('library')
        ->and(Native::visit('/search/read/c1/page/3')->get('number'))->toBe(3);
});
