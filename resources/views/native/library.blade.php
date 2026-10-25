<native:column class="w-full h-full bg-theme-background">
    <native:column class="w-full px-4 pt-2 pb-2 gap-2">
        <native:row class="w-full items-center gap-2 px-3 py-2 rounded-lg bg-[#2C2C2E]">
            <native:icon name="search" size="17" color="#8E8E93" />
            <native:bare-text-input
                class="flex-1"
                placeholder="Search your library"
                :value="$searchText"
                sync-mode="debounce"
                debounce-ms="400"
                @change="searchFor"
                @submit="searchFor"
                a11y-label="Search your library" />
            @if ($searchText !== '')
                <native:pressable @press="clearSearch" a11y-label="Clear search">
                    <native:icon name="close" size="17" color="#8E8E93" />
                </native:pressable>
            @endif
        </native:row>

        <native:scroll-view axis="horizontal" :shows-indicators="false" class="w-full">
            <native:row class="gap-2">
                @foreach ($filters as $option)
                    <native:chip label="{{ ucfirst($option) }}" :selected="$option === $filter" @change="setFilter('{{ $option }}')" />
                @endforeach
            </native:row>
        </native:scroll-view>

        <native:scroll-view axis="horizontal" :shows-indicators="false" class="w-full">
            <native:row class="gap-2">
                @foreach ($sorts as $option)
                    @php($label = ['opened' => 'Last opened', 'downloaded' => 'Recently downloaded', 'added' => 'Date added', 'title' => 'Title'][$option])
                    <native:chip label="{{ $label }}" :selected="$option === $sort" @change="setSort('{{ $option }}')" />
                @endforeach
            </native:row>
        </native:scroll-view>
    </native:column>

    <native:refreshable class="w-full flex-1" @refresh="pullToRefresh">
        <native:column class="w-full gap-2 pb-6">
            @if ($tiles !== [])
                <native:row class="w-full gap-4 px-4 pt-2 pb-4 flex-wrap">
                    @foreach ($tiles as $tile)
                        @include('native.partials.tile', $tile)
                    @endforeach
                </native:row>
            @elseif ($searchText !== '' || $filter !== 'all')
                @include('native.partials.empty-state', [
                    'title' => 'No series match',
                    'body' => 'Try another filter, sort, or search.',
                ])
            @else
                @include('native.partials.empty-state', [
                    'title' => 'Your series will show up here',
                    'body' => 'Favorite a series or start reading one to add it.',
                ])
            @endif

            @if ($interested !== [])
                <native:column class="w-full pt-2">
                    @include('native.partials.row', ['label' => 'You may still be interested', 'tiles' => $interested])
                </native:column>
            @endif
        </native:column>
    </native:refreshable>
</native:column>
