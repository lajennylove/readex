<?php

return [

    'api_url' => 'https://api.mangadex.org',

    'report_url' => 'https://api.mangadex.network/report',

    'covers_url' => 'https://uploads.mangadex.org/covers',

    'app_version' => env('NATIVEPHP_APP_VERSION', '0.1.0'),

    'contact' => env('MANGADEX_CONTACT'),

    'connect_timeout' => 5,

    'timeout' => 20,

    // Identical GETs within this many seconds share one response (UI thread, async tasks, queue worker).
    'share_seconds' => 5,

    'share_store' => env('MANGADEX_SHARE_STORE', 'database'),

    'status_family' => 'mangadex.org',

    'controller' => [
        'throttle_default_seconds' => 60,
        'throttle_factor' => 0.5,
        'throttle_min_fraction' => 0.25,
        'recover_every_seconds' => 60,
        'recover_fraction' => 0.125,
        'ban_base_seconds' => 900,
        'ban_max_seconds' => 7200,
        'probe_timeout_seconds' => 30,
        'usage_keep_minutes' => 1440,
    ],

];
