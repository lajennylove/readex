{{-- Pan is bound only while zoomed: at 1× a drag would move the page off centre. --}}
@if ($page === null)
    <native:column native:key="viewer-missing" class="w-full h-full items-center justify-center p-6 bg-black">
        <native:text class="text-base text-center text-white">Page {{ $number }} isn't on this phone yet</native:text>
    </native:column>
@else
    {{-- Root <native:stack> + gesture-area last so the absolute left-edge
         overlay sits on top on Android (StackRenderer's Box honors
         PositionType.ABSOLUTE; a plain Column places absolute children
         inline instead). --}}
    <native:stack class="w-full h-full bg-black">
    <native:column class="w-full h-full bg-black">
        @if ($zoomed)
            <native:gesture-area class="w-full flex-1 justify-center bg-black" :pinch="$scale" pinch-min="1" pinch-max="{{ \App\NativeComponents\PageViewer::MAX_ZOOM }}" :pan-x="$panX" :pan-y="$panY" @pinchEnd="pinchEnded" @dragEnd="dragEnded">
                <native:image
                    native:key="viewer-page"
                    src="{{ $page['src'] }}"
                    class="w-full aspect-[{{ $page['width'] }}/{{ $page['height'] }}]"
                    :scale="$scale"
                    :translate-x="$panX->clamp(-$boundX, $boundX)"
                    :translate-y="$panY->clamp(-$boundY, $boundY)"
                    alt="Page {{ $number }}"
                    @press="toggleBar"
                    @doubleTap="doubleTapped" />
            </native:gesture-area>
        @else
            <native:gesture-area class="w-full flex-1 justify-center bg-black" :pinch="$scale" pinch-min="1" pinch-max="{{ \App\NativeComponents\PageViewer::MAX_ZOOM }}" @pinchEnd="pinchEnded">
                <native:image
                    native:key="viewer-page"
                    src="{{ $page['src'] }}"
                    class="w-full aspect-[{{ $page['width'] }}/{{ $page['height'] }}]"
                    :scale="$scale"
                    alt="Page {{ $number }}"
                    @press="toggleBar"
                    @doubleTap="doubleTapped" />
            </native:gesture-area>
        @endif
    </native:column>
    @include('native.partials.edge-back-gesture')
    </native:stack>
@endif
