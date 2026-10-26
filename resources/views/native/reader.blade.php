@if ($screen === 'pages')
    {{-- Root <native:stack> + gesture-area last so the absolute left-edge
         overlay sits on top on Android (StackRenderer's Box honors
         PositionType.ABSOLUTE; a plain Column places absolute children
         inline instead). --}}
    <native:stack class="w-full h-full bg-black">
    <native:column class="w-full h-full bg-black">
        <native:virtual-list
            class="w-full flex-1 bg-black"
            :count="$rows"
            :from="$from"
            :to="$to"
            item="native.partials.reader-row"
            :estimated-row-height="$rowHeight"
            :overscan="\App\NativeComponents\Reader::OVERSCAN"
            :shows-indicators="false"
            on-window-change="windowChanged" />
    </native:column>
    @include('native.partials.edge-back-gesture')
    </native:stack>
@elseif ($screen === 'loading')
    <native:column class="w-full h-full gap-1 bg-black">
        @foreach (range(1, 2) as $i)
            @include('native.partials.skeleton', ['key' => "reader-loading-{$i}", 'class' => 'w-full aspect-[5/7]'])
        @endforeach
    </native:column>
@elseif ($screen === 'offline')
    <native:column class="w-full h-full items-center justify-center gap-3 p-6 bg-black">
        <native:text class="text-lg font-bold text-white">Needs internet</native:text>
        <native:text class="text-sm text-center text-theme-on-surface-variant">This chapter isn't on your phone yet. It loads once you're online.</native:text>
        <native:button label="Retry" variant="secondary" @press="retry" />
    </native:column>
@elseif ($screen === 'retrying')
    <native:column class="w-full h-full items-center justify-center gap-3 p-6 bg-black">
        <native:text class="text-lg font-bold text-white">The provider is having trouble</native:text>
        <native:text class="text-sm text-center text-theme-on-surface-variant">Retrying on its own.</native:text>
    </native:column>
@elseif ($screen === 'external')
    <native:column class="w-full h-full items-center justify-center gap-3 p-6 bg-black">
        <native:text class="text-lg font-bold text-white">Opens on {{ $host ?: 'the web' }}</native:text>
        <native:text class="text-sm text-center text-theme-on-surface-variant">This chapter is only on the publisher's site.</native:text>
        <native:button label="Open" variant="secondary" @press="openExternal" />
    </native:column>
@elseif ($screen === 'unavailable')
    <native:column class="w-full h-full items-center justify-center gap-3 p-6 bg-black">
        <native:text class="text-lg font-bold text-white">Not available</native:text>
        <native:text class="text-sm text-center text-theme-on-surface-variant">The provider has no pages for this chapter. It may have been removed.</native:text>
        <native:button label="Retry" variant="secondary" @press="retry" />
    </native:column>
@elseif ($screen === 'failed')
    <native:column class="w-full h-full items-center justify-center gap-3 p-6 bg-black">
        <native:text class="text-lg font-bold text-white">Something went wrong</native:text>
        <native:button label="Retry" variant="secondary" @press="retry" />
    </native:column>
@else
    @include('native.partials.empty-state', ['title' => 'Chapter not found'])
@endif
