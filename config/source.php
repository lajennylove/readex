<?php

return [

    // The runtime source URL is per-user — read from SettingsRepository::sourceUrl() via
    // App\Sources\ActiveSource. These `_default` keys are a BUILD-TIME hint only, used solely
    // by Setup to pre-fill its URL field on first run so a review build (or local dev) can cut
    // one with the env vars set and have the field populated for the first-launch user. Blank
    // in a normal production build -> field is blank, user types their URL in -> same as before.
    'api_url_default' => env('SOURCE_API_URL'),

    // Setup pre-fill companions for the optional Covers and Seasonal URL fields. Blank in a
    // production build -> fields are blank -> same as before. Set at build time via
    // SOURCE_COVERS_URL / SOURCE_SEASONAL_URL to ship an install that opens on a working
    // reader without the user typing anything.
    'covers_url_default' => env('SOURCE_COVERS_URL'),
    'seasonal_url_default' => env('SOURCE_SEASONAL_URL'),

    // When true, the HTTP Client appends `.json` to every path -- the convention a
    // static-file source (e.g. our GH Pages demo for App Review) uses. Production
    // builds leave this unset.
    'static_json' => env('SOURCE_STATIC_JSON', false),

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
