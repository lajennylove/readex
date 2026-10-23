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

    'image_qualities' => ['data-saver', 'original'],

    'page_sizes' => [
        'list' => 100,
        'feed' => 500,
        'ids' => 100,
        'latest' => 100,
    ],

    'seasonal_url' => 'https://antsylich.github.io/mangadex-seasonal/seasonal-list.json',

];
