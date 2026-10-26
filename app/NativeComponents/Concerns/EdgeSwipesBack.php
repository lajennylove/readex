<?php

namespace App\NativeComponents\Concerns;

/**
 * Edge-swipe-to-go-back handler for sub-screens that pair with the
 * `native.partials.edge-back-gesture` Blade partial. A left→right swipe on
 * the thin left-edge gesture strip fires `back()`; any other direction is
 * ignored so a vertical scroll on a chapter list doesn't pop the screen.
 */
trait EdgeSwipesBack
{
    /** Fired by `<native:gesture-area @swipe="edgeSwipeBack">` on the left-edge strip. */
    public function edgeSwipeBack(string $direction): void
    {
        if ($direction === 'right') {
            $this->back();
        }
    }
}
