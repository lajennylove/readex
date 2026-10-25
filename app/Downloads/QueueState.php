<?php

declare(strict_types=1);

namespace App\Downloads;

/**
 * The state machine on the `downloads.state` column (Plan 21).
 *
 * - queued      → waiting on the background lane (comodin excluded; comodin is tracked
 *                 in the `read_now_lane` singleton, not in the downloads table state).
 * - downloading → the background worker has picked it up and is actively fetching pages.
 * - paused      → user hit Pause from the Downloads tab (Phase B).
 * - canceling   → user hit Cancel; the worker observes this between pages and halts,
 *                 then flips the row to `done` so the chain moves on.
 * - done        → terminal: successful, unavailable, cancelled, or failed-for-good.
 */
enum QueueState: string
{
    case Queued = 'queued';
    case Downloading = 'downloading';
    case Paused = 'paused';
    case Canceling = 'canceling';
    case Done = 'done';
}
