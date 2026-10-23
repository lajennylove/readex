<?php

namespace App\NativeComponents;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class Search extends NativeComponent
{
    public function navTitle(): string
    {
        return 'Search';
    }

    public function render(): View
    {
        return view('native.partials.empty-state', ['title' => 'Search']);
    }
}
