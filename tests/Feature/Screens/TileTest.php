<?php

use App\Adapters\Http\RateLimit\Priority;
use App\Jobs\DownloadCovers;
use App\NativeLayouts\TabsLayout;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Native\Mobile\AsyncTask;
use Native\Mobile\Edge\TailwindParser;
use Native\Mobile\Testing\Native;
use Tests\Support\Covers;
use Tests\Support\Skeletons;
use Tests\Support\TileScreen;

beforeEach(function () {
    $this->caches = Covers::useTempCaches();
    Queue::fake();
    $this->async = AsyncTask::fake();
});

afterEach(fn () => AsyncTask::clearFake());

function imageSrcIs(string $src): Closure
{
    return fn (array $node) => ($node['props']['src'] ?? null) === $src;
}

it('pulses a skeleton until the cover is stored, then shows the cover and fades the skeleton out on the same node', function () {
    $manga = Covers::manga('m1', title: 'Frieren');
    TileScreen::reset(['m1']);

    $loading = Native::test(TileScreen::class)
        ->assertSee('Frieren')
        ->assertMissingElement('image');
    $pulsing = Skeletons::pulsing($loading->tree());

    expect($pulsing)->toHaveCount(1)
        ->and(Skeletons::faded($loading->tree()))->toBe([]);

    Covers::store($this->caches, $manga);

    $loaded = Native::test(TileScreen::class)
        ->assertElement('image', imageSrcIs('storage/cache/covers/m1/cover.jpg.256.jpg'));
    $faded = Skeletons::faded($loaded->tree());

    expect(Skeletons::pulsing($loaded->tree()))->toBe([])
        ->and($faded)->toHaveCount(1)
        ->and($faded[0]['id'])->toBe($pulsing[0]['id']);
});

it('gives the same series a different skeleton node in each row', function () {
    Covers::manga('m1', title: 'Frieren');
    Covers::manga('m2', title: 'Second');
    TileScreen::reset(['m1', 'm2'], view: 'tile-rows');

    $collisions = Skeletons::keyCollisions(fn () => $this->tree = Native::test(TileScreen::class)->tree());
    $ids = array_column(Skeletons::all($this->tree), 'id');

    expect($collisions)->toBe([])
        ->and($ids)->toHaveCount(4)
        ->and(array_unique($ids))->toHaveCount(4);
});

it('keeps an empty row in place with four pulsing placeholder tiles', function () {
    TileScreen::reset([], view: 'tile-rows');

    $collisions = Skeletons::keyCollisions(fn () => $this->tree = Native::test(TileScreen::class)->tree());

    expect($collisions)->toBe([])
        ->and(Skeletons::tiles($this->tree))->toBe(8)
        ->and(array_unique(array_column(Skeletons::all($this->tree), 'id')))->toHaveCount(24);
});

it('shows the language badge and the caption only when given', function () {
    Covers::manga('m1', title: 'First');
    Covers::manga('m2', title: 'Second');
    TileScreen::reset(['m1', 'm2'], badges: ['m1' => 'ES'], captions: ['m2' => 'Ch. 12']);

    Native::test(TileScreen::class)
        ->assertElement('badge', fn (array $n) => ($n['props']['label'] ?? null) === 'ES')
        ->assertSee('Ch. 12');

    TileScreen::reset(['m2']);

    Native::test(TileScreen::class)
        ->assertMissingElement('badge')
        ->assertDontSee('Ch. 12');
});

it('opens the series in the Home tab by default', function () {
    Covers::manga('m1', title: 'Frieren');
    TileScreen::reset(['m1']);

    Native::test(TileScreen::class)
        ->tap('Frieren')
        ->assertNavigatedTo('/home/manga/m1');
});

it('opens the series inside the tab the screen belongs to', function () {
    Route::nativeGroup(TabsLayout::class, fn () => Route::native('/search/tiles-test', TileScreen::class));
    Covers::manga('m1', title: 'Frieren');
    TileScreen::reset(['m1']);

    Native::visit('/search/tiles-test')
        ->tap('Frieren')
        ->assertNavigatedTo('/search/manga/m1');
});

it('downloads the first twelve covers right away and queues the rest in the background', function () {
    Http::fake(['api.mangadex.org/covers/*' => Http::response(Covers::jpeg())]);
    $ids = array_map(fn (int $i) => sprintf('m%02d', $i), range(1, 14));
    foreach ($ids as $id) {
        Covers::manga($id);
    }
    TileScreen::reset($ids);

    Native::test(TileScreen::class);

    $this->async->assertDispatchedTimes(1);
    expect(Http::recorded()->map(fn (array $pair) => explode('/', parse_url($pair[0]->url(), PHP_URL_PATH))[2])->all())
        ->toBe(array_slice($ids, 0, 12));
    Queue::assertPushed(DownloadCovers::class, 1);
    Queue::assertPushed(DownloadCovers::class, fn (DownloadCovers $job) => $job->priority === Priority::Prefetch
        && $job->mangaIds === ['m13', 'm14']);
});

it('renders the tile without dropped Tailwind classes', function () {
    config(['app.debug' => true]);
    (fn () => self::$reportedUnsupportedByView = [])->bindTo(null, TailwindParser::class)();
    TailwindParser::clearCache();
    $dropped = [];
    Log::listen(function (MessageLogged $log) use (&$dropped) {
        if (str_contains($log->message, 'dropped unsupported')) {
            $dropped[] = $log->context;
        }
    });
    $manga = Covers::manga('m1', title: 'Frieren');
    Covers::store($this->caches, $manga);
    Covers::manga('m2', title: 'Second');
    TileScreen::reset(['m1', 'm2'], badges: ['m1' => 'ES'], captions: ['m2' => 'Ch. 12']);

    Native::test(TileScreen::class);
    TileScreen::reset([], view: 'tile-rows');
    Native::test(TileScreen::class);

    expect($dropped)->toBe([]);
});
