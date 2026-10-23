{{--
    A pulsing grey block while something loads. $class (size + rounding), $key, optional $loaded:
    once true the block stops pulsing and fades out over what it covered. iOS only animates a
    node it already has, so $key must stay the same across renders and be unique under its
    keyed parent (unkeyed nodes get running ids that shift whenever anything above them changes).
--}}
@php($loaded = $loaded ?? false)
<native:stack
    native:key="{{ $key }}"
    class="{{ $class }} bg-[#3A3A3C] {{ $loaded ? 'opacity-0' : 'opacity-40' }}"
    :animate-loop="! $loaded"
    :animate-duration="$loaded ? 300 : 900"
    animate-easing="{{ $loaded ? 'ease-out' : 'ease-in-out' }}" />
