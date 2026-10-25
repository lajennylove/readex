{{-- $manga (Manga), $title (string), optional $badge ("ES"), $caption (e.g. "Ch. 12"), $chapterId (the tile opens that chapter, else the series), $removable (holding it offers Remove from library), or $longPress to override the action method called with the manga id. Library grid extras: $pinned, $statusChips (list of labels), $newChapterAvailable. Needs ShowsTiles. --}}
@php($cover = app(\App\Covers\CoverStore::class)->path($manga))
@php($press = empty($chapterId) ? "openManga('{$manga->id}')" : "openChapter('{$chapterId}')")
@if (! empty($longPress))
<native:pressable class="w-28" @press="{{ $press }}" @longPress="{{ $longPress }}('{{ $manga->id }}')">
@elseif (! empty($removable))
<native:pressable class="w-28" @press="{{ $press }}" @longPress="askRemoveFromLibrary('{{ $manga->id }}')">
@else
<native:pressable class="w-28" @press="{{ $press }}">
@endif
    <native:column class="w-28 gap-1">
        <native:stack class="w-28 aspect-[2/3] rounded-md bg-[#2C2C2E]">
            @if ($cover)
                <native:image src="{{ $cover }}" class="w-full h-full rounded-md object-cover" alt="{{ $title }}" />
            @endif
            @include('native.partials.skeleton', ['key' => "cover-{$manga->id}", 'class' => 'w-full h-full rounded-md', 'loaded' => $cover !== null])
            @if (! empty($badge))
                <native:badge label="{{ $badge }}" variant="primary" class="absolute top-1 right-1" />
            @endif
            @if (! empty($pinned))
                <native:icon name="pin.fill" size="14" color="#FFFFFF" class="absolute top-1 left-1" />
            @endif
            @if (! empty($newChapterAvailable))
                <native:stack class="absolute bottom-1 right-1 w-2 h-2 rounded-full bg-theme-primary" a11y-label="New chapter available" />
            @endif
        </native:stack>
        <native:text class="text-xs text-theme-on-background" max-lines="2">{{ $title }}</native:text>
        @if (! empty($caption))
            <native:text class="text-xs text-theme-on-surface-variant" max-lines="1">{{ $caption }}</native:text>
        @endif
        @if (! empty($statusChips))
            <native:row class="gap-1 flex-wrap">
                @foreach ($statusChips as $label)
                    <native:badge label="{{ $label }}" variant="secondary" />
                @endforeach
            </native:row>
        @endif
    </native:column>
</native:pressable>
