{{-- Edge-swipe back gesture. GestureArea's @swipe recognizer has no edge-only
     option, so a thin invisible 20 px column pinned to the left edge catches
     the pan without stealing taps from the content behind it. The screen's
     `EdgeSwipesBack::edgeSwipeBack($direction)` only pops on a left→right
     swipe, so a vertical scroll on a chapter list doesn't trigger back.
     Include this at the TOP of a screen's root container (the gesture area
     needs to lay out before the main content). --}}
<native:gesture-area
    class="absolute top-0 left-0 w-5 h-full z-50"
    @swipe="edgeSwipeBack" />
