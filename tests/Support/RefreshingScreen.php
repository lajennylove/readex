<?php

namespace Tests\Support;

use App\NativeComponents\Concerns\RefreshesData;
use App\Refresh\Refresh;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

final class RefreshingScreen extends NativeComponent
{
    use RefreshesData;

    public array $items = [];

    public int $loads = 0;

    public function mount(): void
    {
        $this->load();
    }

    protected function load(): void
    {
        $this->loads++;
        $result = app(FakeRefreshTarget::class)->get();
        $this->items = $result->data ?? [];
        $this->keepFresh($result, Refresh::of([FakeRefreshTarget::class, 'refresh'], 'latest'));
    }

    public function render(): View
    {
        return view('native.partials.status-line', ['line' => $this->statusLine() ?? 'All good']);
    }
}
