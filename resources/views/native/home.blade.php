<native:refreshable class="w-full h-full bg-theme-background" @refresh="pullToRefresh">
    <native:column class="w-full gap-6 pb-6">
        @include('native.partials.status-line', ['line' => $line])

        @if ($nothingSaved && $retrying)
            @include('native.partials.empty-state', ['title' => 'The provider is having trouble', 'body' => 'Retrying on its own.'])
        @elseif ($nothingSaved)
            <native:column class="w-full items-center gap-3 p-6">
                <native:text class="text-lg font-bold text-theme-on-background">Nothing saved yet</native:text>
                <native:text class="text-sm text-center text-theme-on-surface-variant">Home fills in once the provider can be reached.</native:text>
                <native:button label="Retry" variant="primary" @press="pullToRefresh" />
            </native:column>
        @else
            @foreach (\App\NativeComponents\Home::ROWS as $key => $label)
                @if (array_key_exists($key, $rows))
                    @include('native.partials.row', ['label' => $label, 'tiles' => $rows[$key]])
                @endif
            @endforeach
        @endif
    </native:column>
</native:refreshable>
