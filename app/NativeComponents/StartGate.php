<?php

namespace App\NativeComponents;

use App\Repositories\SettingsRepository;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class StartGate extends NativeComponent
{
    public function mount(SettingsRepository $settings): void
    {
        $this->replace(match (true) {
            ! $settings->eulaAcknowledged() => '/eula',
            ! $settings->setupDone() => '/setup',
            default => '/home',
        });
    }

    public function render(): View
    {
        return view('native.partials.empty-state', ['title' => 'ReaDex']);
    }
}
