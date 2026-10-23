<?php

namespace App\NativeComponents;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class Settings extends NativeComponent
{
    public function navTitle(): string
    {
        return 'Settings';
    }

    public function render(): View
    {
        return view('native.partials.empty-state', ['title' => 'Settings']);
    }
}
