<native:column class="w-full h-full items-center justify-center gap-2 p-6 bg-theme-background">
    <native:text class="text-lg font-bold text-theme-on-background">{{ $title }}</native:text>
    @isset($body)
        <native:text class="text-sm text-center text-theme-on-surface-variant">{{ $body }}</native:text>
    @endisset
</native:column>
