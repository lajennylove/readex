<?php

namespace App\Refresh;

enum RefreshStatus: string
{
    case Ok = 'ok';
    case Offline = 'offline';

    /** Our own request budget is used up (BusyReason::Saturated). */
    case Busy = 'busy';

    /** MangaDex answered 429 or the temporary 403 ban. */
    case Limited = 'limited';

    case Failed = 'failed';

    /** Busy and Limited go to the RefreshRepository job; the others are final. */
    public function retriesLater(): bool
    {
        return $this === self::Busy || $this === self::Limited;
    }
}
