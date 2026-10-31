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
    <native:text class="text-xs text-theme-on-surface-variant">Erotica and pornographic need you to be 18 or older. Turning them on unlocks adult-rated content from your source. Content is served by the provider you configure; ReaDex does not host or curate it.</native:text>
</native:column>
