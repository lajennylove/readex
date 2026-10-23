{{-- $label, $tiles (ShowsTiles::tile() arrays). No tiles yet: placeholder tiles, so the row keeps its place. Keyed by label: one series can sit in two rows. Needs ShowsTiles. --}}
<native:column class="w-full gap-2">
    <native:text class="px-4 text-xl font-bold text-theme-on-background">{{ $label }}</native:text>
    <native:scroll-view axis="horizontal" :shows-indicators="false" class="w-full">
        <native:row native:key="row-{{ $label }}" class="gap-3 px-4">
            @forelse ($tiles as $tile)
                @include('native.partials.tile', $tile)
            @empty
                @for ($i = 0; $i < 4; $i++)
                    @include('native.partials.tile-skeleton', ['key' => "placeholder-{$i}"])
                @endfor
            @endforelse
        </native:row>
    </native:scroll-view>
</native:column>
