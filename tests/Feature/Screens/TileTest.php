<?php

use App\Jobs\DownloadCovers;
use App\MangaDex\RateLimit\Priority;
use App\NativeLayouts\TabsLayout;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Native\Mobile\Edge\TailwindParser;
use Native\Mobile\Testing\Native;
use Tests\Support\Covers;
use Tests\Support\TileScreen;

beforeEach(function () {
    $this->caches = Covers::useTempCaches();
    Queue::fake();
});

function imageSrcIs(string $src): Closure
{
    return fn (array $node) => ($node['props']['src'] ?? null) === $src;
}

it('shows a grey placeholder until the cover is stored, then the cover', function () {
    $manga = Covers::manga('m1', title: 'Frieren');
    TileScreen::reset(['m1']);

    Native::test(TileScreen::class)
        ->assertSee('Frieren')
        ->assertMissingElement('image');

    Covers::store($this->caches, $manga);

    Native::test(TileScreen::class)
        ->assertElement('image', imageSrcIs('storage/cache/covers/m1/cover.jpg.256.jpg'));
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

it('queues the first twelve covers as interactive and the rest in the background', function () {
    $ids = array_map(fn (int $i) => sprintf('m%02d', $i), range(1, 14));
    foreach ($ids as $id) {
        Covers::manga($id);
    }
    TileScreen::reset($ids);

    Native::test(TileScreen::class);

    Queue::assertPushed(DownloadCovers::class, 2);
    Queue::assertPushed(DownloadCovers::class, fn (DownloadCovers $job) => $job->priority === Priority::Interactive
        && $job->mangaIds === array_slice($ids, 0, 12));
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

    expect($dropped)->toBe([]);
});
