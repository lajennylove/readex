<?php

namespace App\NativeLayouts;

use App\NativeComponents\Downloads;
use App\NativeComponents\Home;
use App\NativeComponents\Library;
use App\NativeComponents\Search;
use App\NativeComponents\Settings;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Layouts\Builders\NavBar;
use Native\Mobile\Edge\Layouts\Builders\Tab;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Edge\Layouts\NativeLayout;
use Native\Mobile\Edge\NativeComponent;

class TabsLayout extends NativeLayout
{
    /**
     * iOS picks the tab that hosts a pushed screen by URL prefix, so a
     * detail screen opened from Search must live under /search/… or it
     * opens in the Home tab.
     */
    public const DETAIL_TABS = ['home', 'search', 'library'];

    public function usesNativeChrome(): bool
    {
        return true;
    }

    /** Tab-root screen classes — the five screens whose nav bar never shows a back arrow. */
    public const TAB_ROOTS = [
        Home::class,
        Search::class,
        Library::class,
        Downloads::class,
        Settings::class,
    ];

    public function navBar(NativeComponent $screen): ?NavBar
    {
        // Native back arrow on anything that isn't a tab root. The chrome
        // wires the arrow to the screen's own back() method; `->back(true)` on
        // the NavBar builder is enough — no callback plumbing needed.
        return NavBar::make()
            ->title($screen->navTitle())
            ->displayMode('automatic')
            ->back(self::showsBackArrow($screen));
    }

    /** True when the given screen is a pushed detail, not one of the five tab roots. */
    public static function showsBackArrow(NativeComponent $screen): bool
    {
        return ! in_array(get_class($screen), self::TAB_ROOTS, true);
    }

    public function tabBar(NativeComponent $screen): ?TabBar
    {
        return TabBar::make()
            ->add(Tab::link('Home', '/home', icon: 'home', ios: 'house'))
            ->add(Tab::link('Search', '/search', icon: 'search', ios: 'magnifyingglass'))
            ->add(Tab::link('Library', '/library', icon: 'library_books', ios: 'books.vertical'))
            ->add(Tab::link('Downloads', '/downloads', icon: 'download', ios: 'arrow.down.circle'))
            ->add(Tab::link('Settings', '/settings', icon: 'settings', ios: 'gearshape'))
            ->dark()
            ->minimizeOnScroll();
    }

    /** D31: the resume bar on the screens that show it; the Reader and the page viewer don't. iOS draws it on 26+ only. */
    public function tabBarAccessory(NativeComponent $screen): ?Element
    {
        return $screen instanceof HasResumeBar ? $screen->resumeBar() : null;
    }

    public static function mangaUri(string $tab, string $mangaId): string
    {
        return '/'.(in_array($tab, self::DETAIL_TABS, true) ? $tab : 'home').'/manga/'.$mangaId;
    }

    public static function tabOf(?string $uri): string
    {
        $first = explode('/', ltrim((string) $uri, '/'))[0];

        return in_array($first, self::DETAIL_TABS, true) ? $first : 'home';
    }
}
