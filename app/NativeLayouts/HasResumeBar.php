<?php

namespace App\NativeLayouts;

use Native\Mobile\Edge\Element;

/** A TabsLayout screen that shows the resume bar above the tab bar (`ShowsResumeBar`). */
interface HasResumeBar
{
    public function resumeBar(): ?Element;
}
