<?php

namespace App\Pages;

/** How the last page fetch for a chapter ended, for the reader's status line (spec §13). */
enum LoadState: string
{
    /** Every page asked for is on the phone or marked failed. */
    case Done = 'done';

    /** No connection: missing pages wait, nothing is marked failed. */
    case Offline = 'offline';

    /** A DownloadChapter job is queued to continue: there was no request slot or its time budget ran out. */
    case Busy = 'busy';

    /** MangaDex has no @Home pages for this chapter (removed, external). */
    case Unavailable = 'unavailable';

    /** Unexpected error (reported). */
    case Failed = 'failed';
}
