<?php

namespace App\NativeComponents;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class Home extends NativeComponent
{
    public function navTitle(): string
    {
        return 'Home';
    }

    public function render(): View
    {
        return view('native.partials.empty-state', ['title' => 'Home']);
    }
}
