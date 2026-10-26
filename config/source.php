<?php

return [

    // The source URL itself (api_url / covers_url / report_url) is now per-user,
    // read from SettingsRepository::sourceUrl() via App\Sources\ActiveSource.
    // ReaDex ships NO default endpoint — the user supplies one at Setup.

    'app_version' => env('NATIVEPHP_APP_VERSION', '0.1.0'),

    'contact' => env('SOURCE_CONTACT'),

    'connect_timeout' => 5,

    'timeout' => 20,

    // @Home reports are sent inline after each image, so they get short timeouts of their own.
    'report_connect_timeout' => 2,

    'report_timeout' => 3,

    // After a failed @Home report, none are sent for this many seconds.
    'report_skip_seconds' => 60,

    // Identical GETs within this many seconds share one response (UI thread, async tasks, queue worker).
    'share_seconds' => 5,

    'share_store' => env('SOURCE_SHARE_STORE', 'database'),

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
