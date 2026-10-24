{{-- MangaDetail::chapterRow() array. The chapter Continue picks up is highlighted; read chapters are dimmed; downloads have a checkmark. --}}
<native:pressable class="w-full px-2" @press="readChapter('{{ $id }}')" a11y-label="{{ $a11y }}">
    <native:column class="w-full gap-1 px-2 py-2 rounded-lg {{ $current ? 'bg-[#2C2C2E]' : '' }}">
        <native:text class="text-base {{ $current ? 'font-bold' : '' }} {{ $read ? 'text-theme-on-surface-variant' : 'text-theme-on-background' }}" max-lines="2">{{ $label }}</native:text>
        @if ($downloaded)
            <native:row class="gap-1">
                <native:text class="text-sm text-theme-on-surface-variant" max-lines="1">{{ $caption }}</native:text>
                <native:icon name="checkmark.circle.fill" size="13" color="#8E8E93" />
            </native:row>
        @else
            <native:text class="text-sm text-theme-on-surface-variant" max-lines="1">{{ $caption }}</native:text>
        @endif
    </native:column>
</native:pressable>
