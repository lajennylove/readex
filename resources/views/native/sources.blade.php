<native:scroll-view class="w-full h-full bg-theme-background">
    <native:column class="w-full gap-6 p-4 pb-8">
        <native:column class="w-full gap-2">
            <native:text class="text-xs text-theme-on-surface-variant">ReaDex is a reading client you can point at your own content sources. MangaDex ships built in; any source you add is chosen and controlled entirely by you.</native:text>
        </native:column>

        <native:column class="w-full gap-3">
            @foreach ($sources as $source)
                <native:column class="w-full gap-1" native:key="source-{{ $source['key'] }}">
                    <native:row class="w-full items-center justify-between">
                        <native:column class="gap-0">
                            <native:text class="text-base text-theme-on-background">{{ $source['label'] }}</native:text>
                            @if ($source['baseUrl'])
                                <native:text class="text-xs text-theme-on-surface-variant">{{ $source['baseUrl'] }}</native:text>
                            @endif
                            @if ($source['builtin'])
                                <native:text class="text-xs text-theme-on-surface-variant">Built in</native:text>
                            @endif
                        </native:column>
                        <native:toggle
                            native:key="blocked-{{ $source['key'] }}"
                            a11y-label="Block {{ $source['label'] }}"
                            :value="! $source['blocked']"
                            @change="toggleBlocked('{{ $source['key'] }}')" />
                    </native:row>
                    @unless ($source['builtin'])
                        <native:pressable @press="askRemove('{{ $source['key'] }}')">
                            <native:text class="text-sm text-theme-destructive">Remove</native:text>
                        </native:pressable>
                    @endunless
                </native:column>
            @endforeach
        </native:column>

        <native:column class="w-full gap-2">
            <native:text class="text-lg font-bold text-theme-on-background">Add a source</native:text>
            <native:text class="text-xs text-theme-on-surface-variant">A name and the URL or API endpoint it uses. You're responsible for anything you add here (see the Terms screen).</native:text>
            <native:outlined_text_input placeholder="Name" value="{{ $newLabel }}" @change="changeLabel" />
            <native:outlined_text_input placeholder="https://" value="{{ $newUrl }}" @change="changeUrl" />
            @if ($error)
                <native:text class="text-sm text-theme-destructive">{{ $error }}</native:text>
            @endif
            <native:button label="Add source" variant="secondary" @press="addSource" />
        </native:column>
    </native:column>
</native:scroll-view>
