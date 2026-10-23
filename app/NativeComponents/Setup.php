<?php

namespace App\NativeComponents;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class Setup extends NativeComponent
{
    public function render(): View
    {
        return view('native.partials.empty-state', ['title' => 'Welcome to ReaDex']);
    }
}
