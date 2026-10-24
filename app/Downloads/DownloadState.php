<?php

namespace App\Downloads;

/** Where a chapter is in the download queue (spec §11.3), as its Detail row and the Reader bar show it. */
enum DownloadState: string
{
    /** Not stored, or only in the cache tier. */
    case None = 'none';

    /** In the queue, no page in the downloads yet (its job may be waiting on the phone's queue). */
    case Queued = 'queued';

    /** In the queue, pages arriving in the downloads. */
    case Downloading = 'downloading';

    /** In the local tier, partial, out of the queue: Download resumes it. */
    case Stopped = 'stopped';

    /** In the local tier, every page on the phone. */
    case Downloaded = 'downloaded';
}
