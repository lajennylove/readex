@php($setup = \App\NativeComponents\Setup::class)
<native:column class="w-full h-full bg-theme-background safe-area">
    <native:scroll-view class="w-full flex-1">
        <native:column class="w-full gap-4 p-6">
            <native:text class="text-3xl font-bold text-theme-on-background">Welcome to ReaDex</native:text>

            @if ($phase === $setup::SETTINGS)
                <native:column class="w-full gap-2">
                    <native:text class="text-base font-bold text-theme-on-background">Content source</native:text>
                    <native:text class="text-sm text-theme-on-surface-variant">ReaDex has no content of its own. Add the URL or API endpoint of the source you want to read from. The URL is checked before it's saved.</native:text>
                    <native:outlined_text_input placeholder="https://" value="{{ $sourceUrl }}" @change="changeSourceUrl" />
                    @if ($sourceError)
                        <native:text class="text-sm text-theme-destructive">{{ $sourceError }}</native:text>
                    @endif
                </native:column>

                <native:column class="w-full gap-2">
                    <native:text class="text-base font-bold text-theme-on-background">Covers URL (optional)</native:text>
                    <native:text class="text-sm text-theme-on-surface-variant">Only needed when your source serves cover images from a different host than its API.</native:text>
                    <native:outlined_text_input placeholder="https://uploads.example.org/covers" value="{{ $coversUrl }}" @change="changeCoversUrl" />
                </native:column>

                <native:column class="w-full gap-2">
                    <native:text class="text-base font-bold text-theme-on-background">This season (optional)</native:text>
                    <native:text class="text-sm text-theme-on-surface-variant">A JSON URL for the latest content suggestions.</native:text>
                    <native:outlined_text_input placeholder="https://example.org/seasonal-list.json" value="{{ $seasonalUrl }}" @change="changeSeasonalUrl" />
                </native:column>

                <native:text class="text-sm text-theme-on-surface-variant">Pick how you read. You can change all of these later in Settings.</native:text>

                <native:select label="Reading language" value="{{ $languageName }}" :options="$languageNames" @change="chooseLanguage" />

                @include('native.partials.rating-toggles')

                <native:button label="Continue" variant="primary" @press="continueSetup" />
            @elseif ($phase === $setup::OFFLINE)
                <native:text class="text-lg font-bold text-theme-on-background">First launch needs internet</native:text>
                <native:text class="text-sm text-theme-on-surface-variant">Connect to Wi-Fi or mobile data, then try again.</native:text>
                <native:button label="Retry" variant="primary" @press="continueSetup" />
            @else
                <native:text class="text-sm text-theme-on-surface-variant">Getting your provider ready…</native:text>
                <native:column class="w-full gap-2">
                    @foreach (\App\Loading\DataLoader::STEPS as $step => $label)
                        <native:row class="w-full justify-between">
                            <native:text class="text-base text-theme-on-background">{{ $label }}</native:text>
                            <native:text class="text-sm text-theme-on-surface-variant">{{ $setup::stepLine($steps[$step] ?? null) }}</native:text>
                        </native:row>
                    @endforeach
                </native:column>
            @endif
        </native:column>
    </native:scroll-view>
</native:column>
