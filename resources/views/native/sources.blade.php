{{-- Root <native:stack> + gesture-area last so the absolute left-edge overlay
     sits on top on Android (StackRenderer's Box honors PositionType.ABSOLUTE;
     scroll-view's inner column does not). --}}
<native:stack class="w-full h-full bg-theme-background">
<native:scroll-view class="w-full h-full bg-theme-background">
    <native:column class="w-full gap-6 p-4 pb-8">
        <native:column class="w-full gap-2">
            <native:text class="text-xs text-theme-on-surface-variant">ReaDex is a reading client you point at your own content source. The URL you set here is the one source every request goes to. You are responsible for anything you add here.</native:text>
        </native:column>

        <native:column class="w-full gap-2">
            <native:text class="text-base text-theme-on-background">Source URL</native:text>
            <native:outlined_text_input placeholder="https://" value="{{ $editUrl }}" @change="changeEditUrl" />
            @if ($editError)
                <native:text class="text-sm text-theme-destructive">{{ $editError }}</native:text>
            @endif
            @if ($saved)
                <native:text class="text-sm text-theme-success">Saved. The app now uses this URL for every request.</native:text>
            @endif
            <native:button label="Save" variant="primary" @press="saveEdit" />
        </native:column>
    </native:column>
</native:scroll-view>
    @include('native.partials.edge-back-gesture')
</native:stack>
