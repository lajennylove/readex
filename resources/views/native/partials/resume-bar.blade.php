{{-- ShowsResumeBar::resumeBarData(): $title, $line, $a11y, $cover (src, or null: placeholder), $value (0–1 while reading, else null). The slot draws its own glass, so no background here. --}}
<native:pressable native:key="resume-bar" class="w-full px-4 py-2" @press="openResumeBar" a11y-label="{{ $a11y }}">
    <native:row native:key="resume-bar-row" class="w-full gap-3 items-center">
        @if ($cover)
            <native:image native:key="resume-bar-cover" src="{{ $cover }}" class="w-8 h-12 rounded object-cover" />
        @else
            <native:stack native:key="resume-bar-placeholder" class="w-8 h-12 rounded bg-[#2C2C2E] items-center justify-center">
                <native:icon native:key="resume-bar-placeholder-icon" name="book.closed" size="16" color="#8E8E93" />
            </native:stack>
        @endif
        <native:column native:key="resume-bar-text" class="flex-1 gap-1">
            <native:text native:key="resume-bar-title" class="text-sm font-bold text-theme-on-background" max-lines="1">{{ $title }}</native:text>
            <native:text native:key="resume-bar-line" class="text-xs text-theme-on-surface-variant" max-lines="1">{{ $line }}</native:text>
            @if ($value !== null)
                <native:progress-bar native:key="resume-bar-progress" value="{{ $value }}" />
            @endif
        </native:column>
    </native:row>
</native:pressable>
