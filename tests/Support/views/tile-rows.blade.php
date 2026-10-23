<native:column class="gap-4 bg-theme-background">
    @php($tiles = array_map(fn ($manga) => ['manga' => $manga, 'title' => $manga->title['en'] ?? $manga->id, 'badge' => null, 'caption' => null], $mangas))
    @include('native.partials.row', ['label' => 'Row A', 'tiles' => $tiles])
    @include('native.partials.row', ['label' => 'Row B', 'tiles' => $tiles])
</native:column>
