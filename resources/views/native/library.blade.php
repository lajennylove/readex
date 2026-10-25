<native:refreshable class="w-full h-full bg-theme-background" @refresh="pullToRefresh">
    <native:column class="w-full gap-6 pb-6">
        @if ($favorites !== [])
            @include('native.partials.row', ['label' => 'Favorites', 'tiles' => $favorites])
        @endif

        @if ($new !== [])
            @include('native.partials.row', ['label' => 'New', 'tiles' => $new])
        @endif

        @if ($downloaded !== [])
            @include('native.partials.row', ['label' => 'Downloaded', 'tiles' => $downloaded])
        @endif

        @if ($reading !== [])
            @include('native.partials.row', ['label' => 'Reading', 'tiles' => $reading])
        @endif

        @if ($interested !== [])
            @include('native.partials.row', ['label' => 'You may still be interested', 'tiles' => $interested])
        @endif

        @if ($favorites === [] && $new === [] && $downloaded === [] && $reading === [] && $interested === [])
            <native:column class="w-full items-center gap-3 p-6">
                <native:text class="text-lg font-bold text-theme-on-background">Your series will show up here</native:text>
                <native:text class="text-sm text-center text-theme-on-surface-variant">Favorite a series on Detail, or download a chapter, to see it in Library.</native:text>
            </native:column>
        @endif
    </native:column>
</native:refreshable>
