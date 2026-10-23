<native:column class="w-full h-full bg-theme-background">
    @if ($continue !== [])
        <native:column class="w-full pt-2">
            @include('native.partials.row', ['label' => 'Continue reading', 'tiles' => $continue])
        </native:column>
    @endif
    @include('native.partials.empty-state', [
        'title' => 'Your series will show up here',
        'body' => 'Favorite a series or start reading one to add it.',
    ])
</native:column>
