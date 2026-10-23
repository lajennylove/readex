<?php

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
use Native\Mobile\Edge\TailwindParser;
use Native\Mobile\Testing\Native;

it('sends a first launch to Setup', function () {
    Native::visit('/')->assertReplacedWith('/setup');
});

it('sends a set-up phone to Home', function () {
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

it('builds detail URIs per tab', function () {
    expect(TabsLayout::mangaUri('search', 'x'))->toBe('/search/manga/x')
        ->and(TabsLayout::mangaUri('settings', 'x'))->toBe('/home/manga/x')
        ->and(TabsLayout::tabOf('/library/manga/x'))->toBe('library')
        ->and(TabsLayout::tabOf('/settings'))->toBe('home')
        ->and(TabsLayout::tabOf(null))->toBe('home');
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
