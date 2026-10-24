<?php

namespace App\NativeComponents;

use App\Models\SourceConfig;
use App\Repositories\SourceRepository;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Facades\Dialog;

/**
 * Settings → Sources (Plan-08 Task 2, D45 §2/§6 item 3): list every configured source
 * (built-in MangaDex first, never removable), add a source from a generic name + URL/API
 * endpoint (never a suggested or pre-filled third-party name), block/unblock any source
 * (simple local block, D45 §6 item 3 — no reporting backend), remove a user-added source.
 */
class Sources extends NativeComponent
{
    public const REMOVE = 'Remove';

    public string $newLabel = '';

    public string $newUrl = '';

    public ?string $error = null;

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
        } catch (\InvalidArgumentException $e) {
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

    public function render(): View
    {
        $sources = app(SourceRepository::class)->all()->map(fn (SourceConfig $s) => [
            'key' => $s->key,
            'label' => $s->label,
            'builtin' => $s->kind === 'builtin',
            'blocked' => $s->blocked,
            'baseUrl' => $s->config['base_url'] ?? null,
        ])->all();

        return view('native.sources', ['sources' => $sources, 'newLabel' => $this->newLabel, 'newUrl' => $this->newUrl, 'error' => $this->error]);
    }
}
