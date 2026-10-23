<native:row class="gap-3 p-4 bg-theme-background">
    @foreach ($mangas as $manga)
        @include('native.partials.tile', [
            'manga' => $manga,
            'title' => $manga->title['en'] ?? $manga->id,
            'badge' => $badges[$manga->id] ?? null,
            'caption' => $captions[$manga->id] ?? null,
        ])
    @endforeach
</native:row>
