<?php

namespace App\NativeComponents;

use App\NativeComponents\Concerns\EdgeSwipesBack;
use App\Repositories\SettingsRepository;
use App\Sources\ActiveSource;
use App\Sources\SourceHealthCheck;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Settings → Source: shows the one source URL the user configured at first-run Setup
 * and lets them change it. ReaDex is agnostic — there is no built-in source and no
 * source list; exactly one URL is stored, and every HTTP call reads it via
 * App\Sources\ActiveSource. Validation here uses the same SourceHealthCheck probe
 * Setup uses, so a bad or non-conforming URL is never saved.
 */
class Sources extends NativeComponent
{
    use EdgeSwipesBack;

    public string $editUrl = '';

    public ?string $editError = null;

    public bool $saved = false;

    public function navTitle(): string
    {
        return 'Source';
    }

    public function mount(ActiveSource $active): void
    {
        $this->editUrl = $active->isConfigured() ? $active->baseUrl() : '';
    }

    public function changeEditUrl(string $value): void
    {
        $this->editUrl = $value;
        $this->editError = null;
        $this->saved = false;
    }

    public function saveEdit(): void
    {
        $this->saved = false;
        $this->editError = null;

        $result = app(SourceHealthCheck::class)->check($this->editUrl);

        if (! $result->ok) {
            $this->editError = $result->error;

            return;
        }

        app(SettingsRepository::class)->setSourceUrl($this->editUrl);
        $this->saved = true;
    }

    public function render(): View
    {
        return view('native.sources', [
            'editUrl' => $this->editUrl,
            'editError' => $this->editError,
            'saved' => $this->saved,
        ]);
    }
}
