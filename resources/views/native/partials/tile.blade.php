{{-- $manga (Manga), $title (string), optional $badge ("ES"), $caption (e.g. "Ch. 12"). Needs ShowsTiles. --}}
@php($cover = app(\App\Covers\CoverStore::class)->path($manga))
<native:pressable class="w-28" @press="openManga('{{ $manga->id }}')">
    <native:column class="w-28 gap-1">
        <native:stack class="w-28 aspect-[2/3] rounded-md bg-[#2C2C2E]">
            @if ($cover)
                <native:image src="{{ $cover }}" class="w-full h-full rounded-md object-cover" alt="{{ $title }}" />
            @endif
            @if (! empty($badge))
                <native:badge label="{{ $badge }}" variant="primary" class="absolute top-1 right-1" />
            @endif
        </native:stack>
        <native:text class="text-xs text-theme-on-background" max-lines="2">{{ $title }}</native:text>
        @if (! empty($caption))
            <native:text class="text-xs text-theme-on-surface-variant" max-lines="1">{{ $caption }}</native:text>
        @endif
    </native:column>
</native:pressable>
