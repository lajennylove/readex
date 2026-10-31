{{-- Root <native:stack> so native.partials.edge-back-gesture (position: absolute,
     w-5 h-full left-0) actually overlays as an absolute child — on Android the
     refreshable's scrolling Column doesn't honor PositionType.ABSOLUTE for its
     children, only StackRenderer's Box does. --}}
<native:stack class="w-full h-full bg-theme-background">
<native:refreshable class="w-full h-full bg-theme-background" @refresh="pullToRefresh">
    @include('native.partials.status-line', ['line' => $line])

    @if ($manga)
        @php($cover = app(\App\Covers\CoverStore::class)->path($manga))
        <native:row class="w-full gap-4 px-4 pt-2 pb-4">
            {{-- Fixed height, not aspect-[2/3]: the iOS row measures children with no height, so an aspect-only cover overflows the row. --}}
            <native:stack class="w-32 h-48 rounded-md bg-[#2C2C2E]">
                @if ($cover)
                    <native:image src="{{ $cover }}" class="w-full h-full rounded-md object-cover" alt="{{ $title }}" />
                @endif
                @include('native.partials.skeleton', ['key' => 'detail-cover', 'class' => 'w-full h-full rounded-md', 'loaded' => $cover !== null])
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
            @if ($downloadAll)
                <native:button label="{{ $downloadAll['label'] }}" variant="secondary" ios-icon="{{ $downloadAll['icon'] }}" :disabled="$downloadAll['disabled']" @press="downloadAll" />
            @endif
        </native:column>

        @if ($languages !== '')
            <native:text class="px-4 pb-4 text-sm text-theme-on-surface-variant">{{ $languages }}</native:text>
        @endif

        @if ($description)
            <native:text class="px-4 pb-4 text-sm text-theme-on-background">{{ $description }}</native:text>
        @endif

        {{-- Placeholders only while the call is on its way; none recommended, or the call failed: no row. --}}
        @if ($similar !== [] || $loadingSimilar)
            <native:column class="w-full pb-4">
                @include('native.partials.row', ['label' => 'More like this', 'tiles' => $similar])
            </native:column>
        @endif

        <native:text class="px-4 pt-2 pb-2 text-xl font-bold text-theme-on-background">Chapters</native:text>

        @if ($switch !== [])
            {{-- Keyed by the language code (unique, stable across renders): without
                 native:key each chip's node id is the bare positional $nextId++
                 counter, shared with the WHOLE screen's tree rather than scoped to
                 this row. Any unrelated re-render that inserts/removes a sibling
                 anywhere earlier in the tree (e.g. a Continue button appearing once
                 reading progress is saved) then shifts every chip's id, and the
                 native side's per-node selected state (keyed by that id) can land
                 on the wrong chip after the shift lines up with a scroll-view
                 re-layout and a tap — more than one chip then shows selected. See
                 native.partials.row / rating-toggles for the same keying pattern. --}}
            <native:scroll-view axis="horizontal" :shows-indicators="false" class="w-full pb-2">
                <native:row class="gap-2 px-4">
                    @foreach ($switch as $option)
                        <native:chip native:key="chip-{{ $option['code'] }}" label="{{ $option['name'] }}" :selected="$option['code'] === $language" @change="pickLanguage('{{ $option['code'] }}')" />
                    @endforeach
                </native:row>
            </native:scroll-view>
        @endif

        @if ($pagerUp)
            <native:pressable
                native:key="chapter-pager-up"
                @press="pageChapters(-1)"
                a11y-label="{{ $pagerUp['label'] }}">
                <native:text class="px-4 pt-4 pb-2 text-sm text-theme-primary">{{ $pagerUp['label'] }}</native:text>
            </native:pressable>
        @endif
        @forelse ($sections as $section)
            <native:text class="px-4 pt-4 pb-1 text-base font-bold text-theme-on-surface-variant">{{ $section['volume'] }}</native:text>
            @foreach ($section['chapters'] as $row)
                @include('native.partials.chapter-row', $row)
            @endforeach
        @empty
            @if ($loadingChapters)
                @for ($i = 0; $i < 6; $i++)
                    <native:column native:key="chapter-skeleton-{{ $i }}" class="w-full gap-2 px-4 py-2">
                        @include('native.partials.skeleton', ['key' => 'label', 'class' => 'w-48 h-4 rounded-sm'])
                        @include('native.partials.skeleton', ['key' => 'caption', 'class' => 'w-32 h-3 rounded-sm'])
                    </native:column>
                @endfor
            @elseif ($line === null)
                <native:text class="px-4 py-2 text-sm text-theme-on-surface-variant">{{ $languageName === null ? 'No chapters yet' : "No chapters in {$languageName} yet" }}</native:text>
            @endif
        @endforelse
        @if ($pagerDown)
            <native:pressable
                native:key="chapter-pager-down"
                @press="pageChapters(1)"
                a11y-label="{{ $pagerDown['label'] }}">
                <native:text class="px-4 pt-2 pb-4 text-sm text-theme-primary">{{ $pagerDown['label'] }}</native:text>
            </native:pressable>
        @endif

        {{-- 2× the resume bar's height (bar: cover h-12 + outer py-2 ≈ 64 px). The floating bar
             sits above the tab bar on every HasResumeBar screen, including this one; without
             this pad the last chapters hide behind it and you have to swipe-up-and-hold just
             to reach them. h-32 (= 128 px) leaves the last row visible on a normal release. --}}
        <native:column class="w-full h-32" />
    @endif
</native:refreshable>
    @include('native.partials.edge-back-gesture')
</native:stack>
