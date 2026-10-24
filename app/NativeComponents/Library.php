<?php

namespace App\NativeComponents;

use App\NativeComponents\Concerns\ShowsResumeBar;
use App\NativeComponents\Concerns\ShowsTiles;
use App\NativeLayouts\HasResumeBar;
use App\Repositories\ContinueReading;
use App\Repositories\SettingsRepository;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/** Phase 4 placeholder (choice 2): Continue reading tiles; Phase 6 brings the grid and chips. */
class Library extends NativeComponent implements HasResumeBar
{
    use ShowsResumeBar;
    use ShowsTiles;

    public function navTitle(): string
    {
        return 'Library';
    }

    public function mount(): void
    {
        $this->queueCovers($this->continueReading()->map(fn (ContinueReading $c) => $c->manga)->all());
    }

    public function render(): View
    {
        $chain = app(SettingsRepository::class)->chain();

        return view('native.library', [
            'continue' => $this->continueReading()->map(fn (ContinueReading $c) => $this->continueTile($c, $chain))->all(),
        ]);
    }
}
