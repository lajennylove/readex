<?php

namespace App\NativeComponents;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class Library extends NativeComponent
{
    public function navTitle(): string
    {
        return 'Library';
    }

    public function render(): View
    {
        return view('native.partials.empty-state', [
            'title' => 'Your series will show up here',
            'body' => 'Favorite a series or start reading one to add it.',
        ]);
    }
}
