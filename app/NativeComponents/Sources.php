<?php

namespace App\NativeComponents;

use App\Models\SourceConfig;
use App\Repositories\SourceRepository;
use Illuminate\View\View;
use InvalidArgumentException;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Facades\Dialog;

/**
 * Settings → Sources (Plan-08 Task 2/3, D45 §2/§6 item 3): list every configured source
 * (built-in source first, never removable), add a source from a generic name + URL/API
 * endpoint (never a suggested or pre-filled third-party name), block/unblock any source
 * (simple local block, D45 §6 item 3 — no reporting backend), remove a user-added source, and
 * (Jenny 2026-10-24 18:05 EDT) edit a user source's URL from here later, same health-check
 * validation as adding it the first time or at first-run Setup.
 */
class Sources extends NativeComponent
{
    public const REMOVE = 'Remove';

    public const SAVE = 'Save';

    public string $newLabel = '';

    public string $newUrl = '';

    public ?string $error = null;

    /** Key of the source whose URL is being edited, or null. */
    public ?string $editing = null;

    public string $editUrl = '';

    public ?string $editError = null;

    public function navTitle(): string
    {
        return 'Sources';
    }

    public function changeLabel(string $value): void
    {
        $this->newLabel = $value;
    }

    public function changeUrl(string $value): void
    {
        $this->newUrl = $value;
    }

    public function addSource(): void
    {
        $this->error = null;

        try {
            app(SourceRepository::class)->add($this->newLabel, $this->newUrl);
            $this->newLabel = '';
            $this->newUrl = '';
        } catch (InvalidArgumentException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function toggleBlocked(string $key, bool $blocked): void
    {
        app(SourceRepository::class)->setBlocked($key, $blocked);
    }

    public function askRemove(string $key): void
    {
        Dialog::alert(
            'Remove source',
            'This source is removed from ReaDex. It is not affected on its own end.',
            [['label' => 'Cancel', 'style' => 'cancel'], ['label' => self::REMOVE, 'style' => 'destructive']],
        )->buttonPressed(fn (ButtonPressed $pressed) => $pressed->label === self::REMOVE
            ? app(SourceRepository::class)->remove($key)
            : null);
    }

    public function startEdit(string $key): void
    {
        $source = app(SourceRepository::class)->find($key);

        if ($source === null || $source->kind === 'builtin') {
            return;
        }

        $this->editing = $key;
        $this->editUrl = $source->config['base_url'] ?? '';
        $this->editError = null;
    }

    public function cancelEdit(): void
    {
        $this->editing = null;
        $this->editError = null;
    }

    public function changeEditUrl(string $value): void
    {
        $this->editUrl = $value;
    }

    public function saveEdit(): void
    {
        if ($this->editing === null) {
            return;
        }

        $this->editError = null;

        try {
            app(SourceRepository::class)->updateUrl($this->editing, $this->editUrl);
            $this->editing = null;
        } catch (InvalidArgumentException $e) {
            $this->editError = $e->getMessage();
        }
    }

    public function render(): View
    {
        $sources = app(SourceRepository::class)->all()->map(fn (SourceConfig $s) => [
            'key' => $s->key,
            'label' => $s->label,
            'builtin' => $s->kind === 'builtin',
            'blocked' => $s->blocked,
            'baseUrl' => $s->config['base_url'] ?? null,
        ])->all();

        return view('native.sources', [
            'sources' => $sources,
            'newLabel' => $this->newLabel,
            'newUrl' => $this->newUrl,
            'error' => $this->error,
            'editing' => $this->editing,
            'editUrl' => $this->editUrl,
            'editError' => $this->editError,
        ]);
    }
}
