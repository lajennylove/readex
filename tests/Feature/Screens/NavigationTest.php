<?php

use App\Models\Manga;
use App\NativeComponents\Home;
use App\NativeComponents\Library;
use App\NativeComponents\MangaDetail;
use App\NativeComponents\Search;
use App\NativeComponents\Settings;
use App\NativeComponents\Setup;
use App\NativeLayouts\TabsLayout;
use App\Repositories\SettingsRepository;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Native\Mobile\AsyncTask;
use Native\Mobile\Edge\TailwindParser;
use Native\Mobile\Testing\Native;
use Tests\Support\Covers;

// The detail screen loads on open; keep its refreshes and cover downloads off the network.
beforeEach(function () {
    Queue::fake();
    AsyncTask::fake();
});

afterEach(fn () => AsyncTask::clearFake());

it('sends a first launch to the EULA, then Setup', function () {
    Native::visit('/')->assertReplacedWith('/eula');
});

it('sends an acknowledged, not-yet-set-up phone to Setup', function () {
    app(SettingsRepository::class)->acknowledgeEula();

    Native::visit('/')->assertReplacedWith('/setup');
});

it('sends a set-up phone to Home', function () {
    app(SettingsRepository::class)->acknowledgeEula();
    app(SettingsRepository::class)->markSetupDone();

    Native::visit('/')->assertReplacedWith('/home');
});

it('shows Setup without tabs', function () {
    Native::visit('/setup')
        ->assertScreen(Setup::class)
        ->assertSee('Welcome to ReaDex');

    expect(fn () => Native::visit('/setup')->assertHasTabBar())->toThrow(Exception::class);
});

it('puts the four tabs under native chrome', function (string $uri, string $screen, string $label) {
    Native::visit($uri)
        ->assertScreen($screen)
        ->assertHasTabBar()
        ->assertHasTab('Home')
        ->assertHasTab('Search')
        ->assertHasTab('Library')
        ->assertHasTab('Settings')
        ->assertTabActive($label)
        ->assertNavTitle($label);
})->with([
    ['/home', Home::class, 'Home'],
    ['/search', Search::class, 'Search'],
    ['/library', Library::class, 'Library'],
    ['/settings', Settings::class, 'Settings'],
]);

it('forces the dark scheme on the tab chrome', function () {
    // Info.plist pins dark only in our own build; Jump follows the phone, so the
    // nav title would be drawn black on black without the runtime `dark` prop.
    $find = function (array $node) use (&$find): ?array {
        if (($node['type'] ?? null) === 'native_root_tabs') {
            return $node;
        }
        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child) && ($found = $find($child)) !== null) {
                return $found;
            }
        }

        return null;
    };

    $tabs = $find(Native::visit('/home')->tree());

    expect($tabs['props']['dark'] ?? null)->toBeTrue();
});

it('shows the Library placeholder', function () {
    Native::visit('/library')->assertSee('Your series will show up here');
});

it('pushes the detail screen inside the tab it was opened from', function (string $tab, string $label) {
    Native::visit("/{$tab}/manga/abc-123")
        ->assertScreen(MangaDetail::class)
        ->assertSet('mangaId', 'abc-123')
        ->assertSet('tab', $tab)
        ->assertHasTabBar()
        ->assertTabActive($label);
})->with([
    ['home', 'Home'],
    ['search', 'Search'],
    ['library', 'Library'],
]);

it('gives the detail screen a nav title', function () {
    // iOS tabs only build their NavigationStack when the current screen has a title;
    // with an empty one the push showed the tab root's cached tree with no bar.
    Native::visit('/home/manga/abc-123')->assertNavTitle('Series');

    Covers::manga('m1', title: 'Frieren');

    Native::visit('/search/manga/m1')->assertNavTitle('Frieren');
});

it('builds detail URIs per tab', function () {
    expect(TabsLayout::mangaUri('search', 'x'))->toBe('/search/manga/x')
        ->and(TabsLayout::mangaUri('settings', 'x'))->toBe('/home/manga/x')
        ->and(TabsLayout::tabOf('/library/manga/x'))->toBe('library')
        ->and(TabsLayout::tabOf('/settings'))->toBe('home')
        ->and(TabsLayout::tabOf(null))->toBe('home');
});

// Nav back arrow: TabsLayout flags a native back arrow on anything that isn't
// one of the five tab roots. The native-chrome rollout wires that flag to the
// screen's own back() method, so a hand-rolled callback isn't needed.
it('shows a native back arrow on non-root screens', function () {
    expect(TabsLayout::showsBackArrow(new MangaDetail))->toBeTrue();
    foreach ([Home::class, Search::class, Library::class, Settings::class] as $root) {
        expect(TabsLayout::showsBackArrow(new $root))->toBeFalse();
    }
});

// The flag reaches the published tree as the chrome's `back` prop on the
// native_root_tabs node. Assert on a pushed detail screen.
it('emits the back-arrow flag on a pushed detail screen', function () {
    $find = function (array $node) use (&$find): ?array {
        if (($node['type'] ?? null) === 'native_root_tabs') {
            return $node;
        }
        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child) && ($found = $find($child)) !== null) {
                return $found;
            }
        }

        return null;
    };

    $tree = Native::visit('/home/manga/abc-123')->tree();
    $tabs = $find($tree);

    // NavBar::make()->back(true) folds into the chrome root as the `nav_back`
    // prop (NativeComponent prefixes NavBar's toRootProps with `nav`, then
    // NativeRootTabs::applyAttributes rewrites it to snake_case for the wire).
    expect($tabs['props']['nav_back'] ?? null)->toBeTrue();
});

it('does not show a back arrow on a tab root', function () {
    $find = function (array $node) use (&$find): ?array {
        if (($node['type'] ?? null) === 'native_root_tabs') {
            return $node;
        }
        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child) && ($found = $find($child)) !== null) {
                return $found;
            }
        }

        return null;
    };

    $tabs = $find(Native::visit('/home')->tree());

    expect($tabs['props']['nav_back'] ?? null)->toBeFalse();
});

// Edge-swipe back gesture: the handler takes the direction string from the
// GestureArea's @swipe recognizer. Only a right-ward swipe (left→right) pops
// the screen; a vertical scroll on a chapter list must not trigger back.
it('pops the screen on a left-edge right swipe', function () {
    Native::visit('/home/manga/abc-123')
        ->call('edgeSwipeBack', 'right')
        ->assertWentBack();
});

it('ignores non-right edge swipes', function () {
    Native::visit('/home/manga/abc-123')
        ->call('edgeSwipeBack', 'left')
        ->assertNoNavigation();

    Native::visit('/home/manga/abc-123')
        ->call('edgeSwipeBack', 'up')
        ->assertNoNavigation();
});

it('renders every screen without dropped Tailwind classes', function () {
    config(['app.debug' => true]);
    // The parser warns once per view per process; earlier tests may have used it up.
    (fn () => self::$reportedUnsupportedByView = [])->bindTo(null, TailwindParser::class)();
    TailwindParser::clearCache();
    $dropped = [];
    Log::listen(function (MessageLogged $log) use (&$dropped) {
        if (str_contains($log->message, 'dropped unsupported')) {
            $dropped[] = $log->context;
        }
    });

    // A saved series, so the detail screen renders its whole header.
    Covers::manga('x');
    Manga::query()->whereKey('x')->update(['authors' => json_encode(['A']), 'artists' => json_encode(['B']), 'status' => 'ongoing', 'year' => 2020]);

    foreach (['/setup', '/home', '/search', '/library', '/settings', '/home/manga/x'] as $uri) {
        Native::visit($uri);
    }

    expect($dropped)->toBe([]);
});

it('uses the dark Kindle theme in both appearances', function () {
    expect(config('native-ui.theme.light'))->toBe(config('native-ui.theme.dark'))
        ->and(config('native-ui.theme.dark.background'))->toBe('#000000')
        ->and(config('nativephp.appearance'))->toBe('dark');
});
