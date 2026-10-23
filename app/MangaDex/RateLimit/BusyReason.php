<?php

namespace App\MangaDex\RateLimit;

enum BusyReason: string
{
    /** Our own budget is used up for now. */
    case Saturated = 'saturated';

    /** MangaDex answered 429; every mangadex.org call waits. */
    case Throttled = 'throttled';

    /** MangaDex answered with the temporary IP ban (403). */
    case Banned = 'banned';
}
