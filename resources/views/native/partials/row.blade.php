{{-- $label, $tiles (ShowsTiles::tile() arrays). No tiles yet: grey placeholders, so the row keeps its place. Needs ShowsTiles. --}}
<native:column class="w-full gap-2">
    <native:text class="px-4 text-xl font-bold text-theme-on-background">{{ $label }}</native:text>
    <native:scroll-view axis="horizontal" :shows-indicators="false" class="w-full">
        <native:row class="gap-3 px-4">
            @forelse ($tiles as $tile)
                @include('native.partials.tile', $tile)
            @empty
                @for ($i = 0; $i < 4; $i++)
                    <native:stack class="w-28 aspect-[2/3] rounded-md bg-[#2C2C2E]" />
                @endfor
            @endforelse
        </native:row>
    </native:scroll-view>
</native:column>
