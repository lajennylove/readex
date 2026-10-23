{{-- A tile still on its way, the size of native.partials.tile: pulsing cover and two title lines. $key. --}}
<native:column class="w-28 gap-1">
    <native:stack class="w-28 aspect-[2/3] rounded-md bg-[#2C2C2E]">
        @include('native.partials.skeleton', ['key' => "{$key}-cover", 'class' => 'w-full h-full rounded-md'])
    </native:stack>
    @include('native.partials.skeleton', ['key' => "{$key}-title", 'class' => 'w-24 h-3 rounded-sm'])
    @include('native.partials.skeleton', ['key' => "{$key}-caption", 'class' => 'w-16 h-3 rounded-sm'])
</native:column>
