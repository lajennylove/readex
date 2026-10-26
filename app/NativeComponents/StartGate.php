<?php

namespace App\NativeComponents;

use App\Repositories\SettingsRepository;
use App\Sources\ActiveSource;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class StartGate extends NativeComponent
{
    public function mount(SettingsRepository $settings, ActiveSource $source): void
    {
        // `setup_done` can be true from a pre-agnostic build that saved the source URL in a
        // now-dropped place; without a current `source_url` every HTTP call throws
        // NoSourceConfigured. Route those users back through Setup so they type the URL in,
        // same as a fresh install.
        $this->replace(match (true) {
            ! $settings->eulaAcknowledged() => '/eula',
            ! $settings->setupDone() || ! $source->isConfigured() => '/setup',
            default => '/home',
        });
    }

    public function render(): View
    {
        return view('native.partials.empty-state', ['title' => 'ReaDex']);
    }
}
