{{-- Every block is a direct child of refreshable: its LazyVStack only builds the rows on screen, so long chapter lists stay cheap natively. --}}
<native:refreshable class="w-full h-full bg-theme-background" @refresh="pullToRefresh">
    @include('native.partials.status-line', ['line' => $line])

    @if ($manga)
        @php($cover = app(\App\Covers\CoverStore::class)->path($manga))
        <native:row class="w-full gap-4 px-4 pt-2 pb-4">
            <native:stack class="w-32 aspect-[2/3] rounded-md bg-[#2C2C2E]">
                @if ($cover)
                    <native:image src="{{ $cover }}" class="w-full h-full rounded-md object-cover" alt="{{ $title }}" />
                @endif
            </native:stack>
            <native:column class="flex-1 gap-1">
                <native:text class="text-xl font-bold text-theme-on-background" max-lines="4">{{ $title }}</native:text>
                @foreach ($people as $person)
                    <native:text class="text-sm text-theme-on-background" max-lines="2">{{ $person }}</native:text>
                @endforeach
                @if ($facts !== '')
                    <native:text class="text-sm text-theme-on-surface-variant">{{ $facts }}</native:text>
                @endif
                @if ($stats)
                    <native:text class="text-sm text-theme-on-surface-variant">{{ $stats }}</native:text>
                @endif
            </native:column>
        </native:row>

        <native:column class="w-full gap-2 px-4 pb-4">
            @if ($continue)
                <native:button label="{{ $continue }}" variant="primary" ios-icon="play.fill" @press="resumeReading" />
            @endif
            <native:button label="{{ $favorite ? 'Favorited' : 'Favorite' }}" variant="secondary" ios-icon="{{ $favorite ? 'heart.fill' : 'heart' }}" @press="toggleFavorite" />
        </native:column>

        @if ($languages !== '')
            <native:text class="px-4 pb-4 text-sm text-theme-on-surface-variant">{{ $languages }}</native:text>
        @endif

        @if ($description)
            <native:text class="px-4 pb-4 text-sm text-theme-on-background">{{ $description }}</native:text>
        @endif

        <native:text class="px-4 pt-2 pb-2 text-xl font-bold text-theme-on-background">Chapters</native:text>

        @if ($switch !== [])
            <native:scroll-view axis="horizontal" :shows-indicators="false" class="w-full pb-2">
                <native:row class="gap-2 px-4">
                    @foreach ($switch as $option)
                        <native:chip label="{{ $option['name'] }}" :selected="$option['code'] === $language" @change="pickLanguage('{{ $option['code'] }}')" />
                    @endforeach
                </native:row>
            </native:scroll-view>
        @endif

        @forelse ($sections as $section)
            <native:text class="px-4 pt-4 pb-1 text-base font-bold text-theme-on-surface-variant">{{ $section['volume'] }}</native:text>
            @foreach ($section['chapters'] as $row)
                @include('native.partials.chapter-row', $row)
            @endforeach
        @empty
            @if ($loadingChapters)
                <native:activity-indicator class="w-full p-6" />
            @elseif ($line === null)
                <native:text class="px-4 py-2 text-sm text-theme-on-surface-variant">{{ $languageName === null ? 'No chapters yet' : "No chapters in {$languageName} yet" }}</native:text>
            @endif
        @endforelse

        <native:column class="w-full h-6" />
    @endif
</native:refreshable>
