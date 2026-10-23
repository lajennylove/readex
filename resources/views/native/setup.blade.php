@php($setup = \App\NativeComponents\Setup::class)
<native:column class="w-full h-full bg-theme-background safe-area">
    <native:scroll-view class="w-full flex-1">
        <native:column class="w-full gap-4 p-6">
            <native:text class="text-3xl font-bold text-theme-on-background">Welcome to ReaDex</native:text>

            @if ($phase === $setup::SETTINGS)
                <native:text class="text-sm text-theme-on-surface-variant">Pick how you read. You can change both later in Settings.</native:text>

                <native:select label="Reading language" value="{{ $languageName }}" :options="$languageNames" @change="chooseLanguage" />

                <native:column class="w-full gap-2">
                    <native:text class="text-lg font-bold text-theme-on-background">Content rating</native:text>
                    {{-- mobile-ui's iOS toggle draws its label in the system colour, black outside the dark tabs. --}}
                    @foreach (config('readex.content_ratings') as $rating)
                        <native:row class="w-full items-center justify-between">
                            <native:text class="text-base text-theme-on-background">{{ ucfirst($rating) }}</native:text>
                            <native:toggle
                                native:key="rating-{{ $rating }}-{{ $ratingToggles }}"
                                a11y-label="{{ ucfirst($rating) }}"
                                :value="in_array($rating, $ratings, true)"
                                @change="setRating('{{ $rating }}')" />
                        </native:row>
                    @endforeach
                    <native:text class="text-xs text-theme-on-surface-variant">Erotica and pornographic need you to be 18 or older.</native:text>
                </native:column>

                <native:button label="Continue" variant="primary" @press="continueSetup" />
            @elseif ($phase === $setup::OFFLINE)
                <native:text class="text-lg font-bold text-theme-on-background">First launch needs internet</native:text>
                <native:text class="text-sm text-theme-on-surface-variant">Connect to Wi-Fi or mobile data, then try again.</native:text>
                <native:button label="Retry" variant="primary" @press="continueSetup" />
            @else
                <native:text class="text-sm text-theme-on-surface-variant">Getting MangaDex ready…</native:text>
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
