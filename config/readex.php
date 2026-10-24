<?php

return [

    // Spec §7. Seconds after fetched_at before get() marks the data stale.
    'refresh_ages' => [
        'tags' => 30 * 86400,
        'home.season' => 86400,
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
        // MangaDex thumbnail width (it serves .256.jpg and .512.jpg).
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
        // A page one fetcher is downloading is skipped by the others for this long.
        'claim_seconds' => 60,
        // A page that failed twice shows "Tap to retry" for this long.
        'failed_seconds' => 86400,
    ],

    'reader' => [
        // Points. PHP gets no screen width; only used for the height of rows outside the list window (iPhone 17 Pro Max).
        'width' => 440,
        // Width / height of a page before any page of the chapter is stored (a typical manga page).
        'page_ratio' => [5, 7],
        // Pages past the list window the reader asks for, so the next ones are there before they scroll in.
        'lookahead' => 3,
        // While offline, the reader asks for its missing pages again this often.
        'offline_retry_seconds' => 15,
    ],

    // First run (spec §9 step 3): Home opens after this long once a Home row has loaded.
    'setup_cutoff_seconds' => 10,

    'seasonal_url' => 'https://antsylich.github.io/mangadex-seasonal/seasonal-list.json',

];
