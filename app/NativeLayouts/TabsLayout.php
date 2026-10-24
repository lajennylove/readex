<?php

namespace App\NativeLayouts;

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

    public function navBar(NativeComponent $screen): ?NavBar
    {
        return NavBar::make()
            ->title($screen->navTitle())
            ->displayMode('automatic');
    }

    public function tabBar(NativeComponent $screen): ?TabBar
    {
        return TabBar::make()
            ->add(Tab::link('Home', '/home', icon: 'home', ios: 'house'))
            ->add(Tab::link('Search', '/search', icon: 'search', ios: 'magnifyingglass'))
            ->add(Tab::link('Library', '/library', icon: 'library_books', ios: 'books.vertical'))
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
