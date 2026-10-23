<native:column class="w-full h-full bg-theme-background">
    @include('native.partials.status-line', ['line' => $line])

    @isset($groups)
        <native:refreshable class="w-full flex-1" @refresh="pullToRefresh">
            <native:column class="w-full gap-5 p-4">
                @forelse ($groups as $heading => $tags)
                    <native:column class="w-full gap-2">
                        <native:text class="text-xl font-bold text-theme-on-background">{{ $heading }}</native:text>
                        <native:row class="w-full flex-wrap gap-2">
                            @foreach ($tags as $tag)
                                <native:chip label="{{ $tag['name'] }}" @change="openTag('{{ $tag['id'] }}')" />
                            @endforeach
                        </native:row>
                    </native:column>
                @empty
                    @if ($busy)
                        <native:activity-indicator class="w-full p-6" />
                    @else
                        <native:column class="w-full items-center gap-3 p-6">
                            <native:text class="text-lg font-bold text-theme-on-background">No tags saved yet</native:text>
                            <native:button label="Retry" variant="primary" @press="pullToRefresh" />
                        </native:column>
                    @endif
                @endforelse
            </native:column>
        </native:refreshable>
    @else
        @if ($tag !== null || $query !== '')
            <native:row class="w-full flex-wrap gap-2 px-4 py-2">
                @if ($tag !== null)
                    <native:chip label="{{ $tag }}" :selected="true" @change="clearTag" />
                @endif
                @if ($query !== '')
                    <native:chip label="“{{ $query }}”" :selected="true" @change="clearQuery" />
                @endif
            </native:row>
        @endif

        @if ($rowCount > 0)
            <native:virtual-list
                class="w-full flex-1"
                :count="$rowCount"
                :from="$from"
                :to="$to"
                item="native.partials.search-row"
                :estimated-row-height="\App\NativeComponents\Search::ROW_HEIGHT"
                :overscan="2"
                on-window-change="windowChanged" />
        @elseif ($busy)
            <native:activity-indicator class="w-full p-6" />
        @elseif ($line !== null)
            <native:column class="w-full items-center gap-3 p-6">
                <native:text class="text-lg font-bold text-theme-on-background">Nothing saved for this search</native:text>
                <native:button label="Retry" variant="primary" @press="pullToRefresh" />
            </native:column>
        @else
            @include('native.partials.empty-state', ['title' => 'No series found', 'body' => 'Try another title or tag.'])
        @endif
    @endisset
</native:column>
