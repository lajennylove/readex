<native:refreshable class="w-full h-full bg-theme-background" @refresh="pullToRefresh">
    <native:column class="w-full gap-4 pb-6">
        @include('native.partials.status-line', ['line' => $line])

        @if ($manga)
            @php($cover = app(\App\Covers\CoverStore::class)->path($manga))
            <native:row class="w-full gap-4 px-4 pt-2">
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

            <native:column class="w-full gap-2 px-4">
                @if ($continue)
                    <native:button label="{{ $continue }}" variant="primary" ios-icon="play.fill" @press="resumeReading" />
                @endif
                <native:button label="{{ $favorite ? 'Favorited' : 'Favorite' }}" variant="secondary" ios-icon="{{ $favorite ? 'heart.fill' : 'heart' }}" @press="toggleFavorite" />
            </native:column>

            @if ($languages !== '')
                <native:text class="px-4 text-sm text-theme-on-surface-variant">{{ $languages }}</native:text>
            @endif

            @if ($description)
                <native:text class="px-4 text-sm text-theme-on-background">{{ $description }}</native:text>
            @endif
        @endif
    </native:column>
</native:refreshable>
