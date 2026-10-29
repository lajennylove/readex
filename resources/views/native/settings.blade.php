<native:scroll-view class="w-full h-full bg-theme-background">
    <native:column class="w-full gap-6 p-4 pb-8">
        <native:column class="w-full gap-2">
            <native:select label="Reading language" value="{{ $languageName }}" :options="$languageNames" @change="chooseLanguage" />
            <native:text class="text-xs text-theme-on-surface-variant">Titles and chapters in this language come first, then English.</native:text>
        </native:column>

        @include('native.partials.rating-toggles')

        <native:column class="w-full gap-2">
            <native:pressable class="w-full" @press="openSources" a11y-label="Sources">
                <native:row class="w-full items-center justify-between">
                    <native:text class="text-base text-theme-on-background">Sources</native:text>
                    <native:text class="text-sm text-theme-on-surface-variant">›</native:text>
                </native:row>
            </native:pressable>
            <native:text class="text-xs text-theme-on-surface-variant">{{ $providerLabel }} plus any content sources you've added. Block, unblock or remove one, or add your own.</native:text>
        </native:column>

        <native:column class="w-full gap-2">
            <native:select label="Image quality" value="{{ $qualityName }}" :options="$qualityNames" @change="chooseQuality" />
            <native:text class="text-xs text-theme-on-surface-variant">HD uses about 8× more space and data. A chapter keeps the quality it was saved in.</native:text>
        </native:column>

        <native:column class="w-full gap-2">
            <native:text class="text-lg font-bold text-theme-on-background">Provider data</native:text>
            <native:row class="w-full items-center justify-between">
                <native:text class="text-base text-theme-on-background">Provider connection</native:text>
                <native:text class="text-base text-theme-on-surface-variant">{{ $connection['state'] }}</native:text>
            </native:row>
            <native:text class="text-xs text-theme-on-surface-variant">{{ $connection['usage'] }}</native:text>

            <native:button
                label="{{ $refreshLabel }}"
                variant="secondary"
                :disabled="$refreshBlocked"
                :loading="$refilling"
                @press="askRefresh" />
            @if ($refreshNote)
                <native:text class="text-sm text-theme-on-background">{{ $refreshNote }}</native:text>
            @endif
            <native:text class="text-xs text-theme-on-surface-variant">Clears saved provider data and loads it again. Your Library, reading progress and downloads stay. {{ $lastRefreshed }}.</native:text>

            @if ($run !== null)
                <native:column class="w-full gap-2">
                    @foreach ($stepLabels as $step => $label)
                        <native:row class="w-full justify-between">
                            <native:text class="text-base text-theme-on-background">{{ $label }}</native:text>
                            <native:text class="text-sm text-theme-on-surface-variant">{{ \App\NativeComponents\Setup::stepLine($steps[$step] ?? null) }}</native:text>
                        </native:row>
                    @endforeach
                </native:column>
            @endif
        </native:column>

        <native:column class="w-full gap-2">
            <native:text class="text-lg font-bold text-theme-on-background">Storage</native:text>
            <native:text class="text-base text-theme-on-background">{{ $storage }}</native:text>
            @if ($storageLow)
                <native:text class="text-sm text-theme-destructive">Storage is almost full. Free up space on this device before downloading or reading more.</native:text>
            @endif
            <native:button label="Clear image cache" variant="secondary" @press="askClearCache" />
            <native:text class="text-xs text-theme-on-surface-variant">Chapters you opened longest ago go first when the cache is full. Clearing keeps your Library, reading progress and downloads.</native:text>
        </native:column>

        <native:column class="w-full gap-2">
            <native:text class="text-lg font-bold text-theme-destructive">Reset ReaDex</native:text>
            <native:button label="Change source URL" variant="destructive" @press="askChangeSource" />
            <native:text class="text-xs text-theme-on-surface-variant">Removes your Library, reading progress, downloads and cached chapters, then takes you back to Setup to enter a new source URL. Content from a different source wouldn't match what's saved here.</native:text>
            <native:button label="Delete everything" variant="destructive" @press="askFactoryReset" />
            <native:text class="text-xs text-theme-on-surface-variant">Deletes your whole Library, reading progress, every download, every cached image, your content sources and all settings. This can't be undone.</native:text>
        </native:column>

        <native:column class="w-full gap-2">
            <native:text class="text-lg font-bold text-theme-on-background">About</native:text>
            <native:pressable class="w-full" @press="openProvider" a11y-label="Data from {{ $providerLabel }}">
                <native:row class="w-full items-center justify-between">
                    <native:text class="text-base text-theme-on-background">Data from {{ $providerLabel }}</native:text>
                    <native:text class="text-sm text-theme-on-surface-variant">{{ parse_url($providerUrl, PHP_URL_HOST) }}</native:text>
                </native:row>
            </native:pressable>
            <native:text class="text-xs text-theme-on-surface-variant">{{ $providerCredit }}</native:text>
            <native:text class="text-xs text-theme-on-surface-variant">ReaDex {{ $version }}</native:text>
        </native:column>
    </native:column>
</native:scroll-view>
