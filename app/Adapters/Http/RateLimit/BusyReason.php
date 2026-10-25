<?php

namespace App\Adapters\Http\RateLimit;

enum BusyReason: string
{
    /** Our own budget is used up for now. */
    case Saturated = 'saturated';

    /** The source answered 429; every call to that source waits. */
    case Throttled = 'throttled';

    /** The source answered with the temporary IP ban (403). */
    case Banned = 'banned';
}
