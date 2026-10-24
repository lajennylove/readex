<?php

use App\NativeComponents\Home;
use App\NativeComponents\Library;
use App\NativeComponents\MangaDetail;
use App\NativeComponents\Reader;
use App\NativeComponents\Search;
use App\NativeComponents\Settings;
use App\NativeComponents\Setup;
use App\NativeComponents\StartGate;
use App\NativeLayouts\TabsLayout;
use Illuminate\Support\Facades\Route;

Route::native('/', StartGate::class);
Route::native('/setup', Setup::class);

Route::nativeGroup(TabsLayout::class, function () {
    Route::native('/home', Home::class);
    Route::native('/search', Search::class);
    Route::native('/library', Library::class);
    Route::native('/settings', Settings::class);

    foreach (TabsLayout::DETAIL_TABS as $tab) {
        Route::native("/{$tab}/manga/{id}", MangaDetail::class);
        Route::native("/{$tab}/read/{chapterId}", Reader::class);
    }
});
