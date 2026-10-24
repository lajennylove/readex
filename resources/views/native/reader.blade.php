@if ($screen === 'pages')
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
@elseif ($screen === 'external')
    <native:column class="w-full h-full items-center justify-center gap-3 p-6 bg-black">
        <native:text class="text-lg font-bold text-white">Opens on {{ $host ?: 'the web' }}</native:text>
        <native:text class="text-sm text-center text-theme-on-surface-variant">This chapter is only on the publisher's site.</native:text>
        <native:button label="Open" variant="secondary" @press="openExternal" />
    </native:column>
@elseif ($screen === 'unavailable')
    @include('native.partials.empty-state', ['title' => 'Not available', 'body' => 'MangaDex has no pages for this chapter. It may have been removed.'])
@else
    @include('native.partials.empty-state', ['title' => 'Chapter not found'])
@endif
