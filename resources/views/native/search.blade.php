<native:column class="w-full h-full bg-theme-background">
    <native:column class="w-full px-4 pt-2 {{ isset($groups) || $tag !== null ? 'pb-1' : 'pb-3' }}">
        <native:row class="w-full items-center gap-2 px-3 py-2 rounded-lg bg-[#2C2C2E]">
            <native:icon name="search" size="17" color="#8E8E93" />
            <native:bare-text-input
                class="flex-1"
                placeholder="Titles"
                :value="$text"
                sync-mode="debounce"
                debounce-ms="400"
                @change="searchFor"
                @submit="searchFor"
                a11y-label="Search titles" />
            @if ($text !== '')
                <native:pressable @press="clearQuery" a11y-label="Clear search">
                    <native:icon name="close" size="17" color="#8E8E93" />
                </native:pressable>
            @endif
        </native:row>
    </native:column>

    @include('native.partials.status-line', ['line' => $line])

    @isset($groups)
        <native:refreshable class="w-full flex-1" @refresh="pullToRefresh">
            <native:column class="w-full gap-5 py-4">
                @forelse ($groups as $heading => $lines)
                    <native:column class="w-full gap-2">
                        <native:text class="px-4 text-xl font-bold text-theme-on-background">{{ $heading }}</native:text>
                        <native:scroll-view axis="horizontal" :shows-indicators="false" class="w-full">
                            <native:column class="gap-2 px-4">
                                @foreach ($lines as $chips)
                                    <native:row class="gap-2">
                                        @foreach ($chips as $tag)
                                            <native:chip label="{{ $tag['name'] }}" @change="openTag('{{ $tag['id'] }}')" />
                                        @endforeach
                                    </native:row>
                                @endforeach
                            </native:column>
                        </native:scroll-view>
                    </native:column>
                @empty
                    @if ($busy)
                        @for ($g = 0; $g < 3; $g++)
                            <native:column native:key="tags-skeleton-{{ $g }}" class="w-full gap-2 px-4">
                                @include('native.partials.skeleton', ['key' => 'heading', 'class' => 'w-24 h-6 rounded-sm'])
                                @for ($l = 0; $l < 2; $l++)
                                    <native:row class="gap-2">
                                        @for ($c = 0; $c < 5; $c++)
                                            @include('native.partials.skeleton', ['key' => "chip-{$l}-{$c}", 'class' => ['w-20', 'w-16', 'w-24'][($c + $l) % 3].' h-8 rounded-full'])
                                        @endfor
                                    </native:row>
                                @endfor
                            </native:column>
                        @endfor
                    @elseif ($retrying)
                        @include('native.partials.empty-state', ['title' => 'MangaDex is having trouble', 'body' => 'Retrying on its own.'])
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
        @if ($tag !== null)
            <native:row class="w-full flex-wrap gap-2 px-4 py-2">
                <native:chip label="{{ $tag }}" :selected="true" @change="clearTag" />
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
            <native:column class="w-full pt-2">
                @for ($r = 0; $r < 3; $r++)
                    <native:row native:key="results-skeleton-{{ $r }}" class="w-full gap-4 px-4 pb-4">
                        @for ($c = 0; $c < \App\NativeComponents\Search::COLUMNS; $c++)
                            @include('native.partials.tile-skeleton', ['key' => "tile-{$c}"])
                        @endfor
                    </native:row>
                @endfor
            </native:column>
        @elseif ($retrying)
            @include('native.partials.empty-state', ['title' => 'MangaDex is having trouble', 'body' => 'Retrying on its own.'])
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
