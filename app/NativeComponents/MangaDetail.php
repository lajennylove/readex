<?php

namespace App\NativeComponents;

use App\NativeLayouts\TabsLayout;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class MangaDetail extends NativeComponent
{
    public string $mangaId = '';

    public string $tab = 'home';

    public function mount(string $id): void
    {
        $this->mangaId = $id;
        $this->tab = TabsLayout::tabOf($this->nativeRouter?->currentUri());
    }

    public function render(): View
    {
        return view('native.partials.empty-state', ['title' => 'Series']);
    }
}
