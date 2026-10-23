<?php

namespace App\NativeComponents;

use App\Models\Manga;
use App\NativeLayouts\TabsLayout;
use App\Repositories\SettingsRepository;
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

    /** Never empty: iOS tabs drop the NavigationStack for an untitled screen and show the tab root instead. */
    public function navTitle(): string
    {
        $manga = Manga::query()->find($this->mangaId);

        if ($manga === null) {
            return 'Series';
        }

        return app(SettingsRepository::class)->chain()->title($manga->title ?? [], $manga->alt_titles ?? []);
    }

    public function render(): View
    {
        return view('native.partials.empty-state', ['title' => 'Series']);
    }
}
