<?php

return [

    // Spec §7. Seconds after fetched_at before get() marks the data stale.
    'refresh_ages' => [
        'tags' => 30 * 86400,
        'home.catalog' => 24 * 3600,
        'home.popular' => 6 * 3600,
        'home.latest' => 15 * 60,
        'list' => 3600,
        'manga' => 86400,
        'feed' => 3600,
        'feed.library' => 15 * 60,
        'statistics' => 86400,
        'recommendations' => 7 * 86400,
        'at_home' => 10 * 60,
    ],

    // Search and tag lists not refreshed for this long are deleted.
    'list_keep_seconds' => 7 * 86400,

    'defaults' => [
        'language' => 'en',
        'content_ratings' => ['safe', 'suggestive'],
        'image_quality' => 'data-saver',
    ],

    'content_ratings' => ['safe', 'suggestive', 'erotica', 'pornographic'],

    // Search shows these after the rest, under a "Rated content" title.
    'rated_content' => ['erotica', 'pornographic'],

    'image_qualities' => ['data-saver', 'original'],

    'page_sizes' => [
        'list' => 100,
        'feed' => 500,
        'ids' => 100,
        'latest' => 100,
        // Tiles per Home row (Popular new, Latest updates).
        'home_row' => 20,
    ],

    // Popular new titles: series created in the last this-many days.
    'popular_new_days' => 30,

    'covers' => [
        // Source thumbnail width (the source serves .256.jpg and .512.jpg).
        'size' => 256,
        // Tiles a screen shows first; their covers download at interactive priority.
        'visible' => 12,
        // A queued cover isn't queued again for this long (screens re-render every few seconds).
        'queued_seconds' => 600,
    ],

    'pages' => [
        // A FetchPages task counts as running this long (safety net if its thread dies).
        'running_seconds' => 60,
        // FetchPages stops taking new pages after this long; the rest goes to DownloadChapter.
        'task_budget_seconds' => 30,
        // A DownloadChapter slice stops taking new pages after this long and releases itself to continue.
        'job_budget_seconds' => 40,
        // A page one fetcher is downloading is skipped by the others for this long.
        'claim_seconds' => 60,
        // A page that failed twice shows "Tap to retry" for this long.
        'failed_seconds' => 86400,
        // A chapter Clear image cache or the cap dropped isn't begun again by a background cache job for this long (a DownloadChapter gives up after an hour).
        'evicted_seconds' => 3600,
    ],

    'reader' => [
        // Points. PHP gets no screen width; only used for the height of rows outside the list window (iPhone 17 Pro Max).
        'width' => 440,
        // Points. The page viewer's height below the nav bar (same phone), for how far a zoomed page may pan.
        'viewer_height' => 820,
        // Width / height of a page before any page of the chapter is stored (a typical manga page).
        'page_ratio' => [5, 7],
        // Pages past the list window the reader asks for, so the next ones are there before they scroll in.
        'lookahead' => 3,
        // While offline, the reader asks for its missing pages again this often.
        'offline_retry_seconds' => 15,
        // Milliseconds Detail pauses after opening the chapter a tile sent: iOS drops a frame followed at once by the next one, so Detail would be missing under the Reader.
        'via_detail_settle_ms' => 350,
    ],

    'cache' => [
        // Spec §11.1: the cache tier (covers, opened and prefetched chapters) is trimmed to this, oldest-read first. Decimal, like iOS Storage.
        'cap_bytes' => 1_000_000_000,
        // Chapters PrefetchNext queues after the one being read (D33 choice 6).
        'prefetch_chapters' => 2,
    ],

    // Disk-space guardrails (incident 2026-10-25: an overnight retry loop filled the device's
    // disk through unbounded log growth; a future bug of any kind must not be able to repeat
    // that on a phone no one is watching).
    'storage' => [
        // DiskSpaceGuard skips/throws below this much free space on the app's own storage
        // volume (disk_free_space() against the caches path — iOS gives no total-device-free
        // API). A tenth of cache.cap_bytes: generous enough to leave the OS and other apps
        // room on an otherwise-full phone, small enough it never fires during normal use.
        'min_free_bytes' => 100_000_000,
    ],

    'logging' => [
        // EnforceLogCap last-resort backstop: storage/logs/laravel.log is truncated once it
        // passes this size, regardless of which Monolog driver/rotation is active. Laravel's
        // daily driver rotates by date, not by size within a day, so a tight retry loop can
        // still fill a day's file; this threshold is far above any legitimate day of app logs
        // but about 1,400x smaller than the 35 GB the overnight incident produced, so it
        // catches a runaway loop within minutes instead of hours.
        'max_log_bytes' => 25_000_000,
    ],

    // First run (spec §9 step 3): Home opens after this long once a Home row has loaded.
    'setup_cutoff_seconds' => 10,

    'refreshes_data' => [
        // Spec §7 polling cadence (RefreshesData::checkWaitingRefreshes).
        // iOS keeps the historical 3s tick; Android lengthens it so colour e-ink
        // devices (e.g. Bigme HiBreak Pro Color) aren't held under the ~700ms
        // PHP render time of each tick. Patched into the vendor's private
        // pollDefinitions cache on first fire (see RefreshesData trait).
        'ios_poll_ms' => 3000,
        'android_poll_ms' => 15000,
    ],

];
