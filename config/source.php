<?php

return [

    // The runtime source URL is per-user — read from SettingsRepository::sourceUrl() via
    // App\Sources\ActiveSource. These `_default` keys are a BUILD-TIME hint used solely by
    // Setup to pre-fill the three URL fields on first run, so a user who installs from
    // TestFlight or the Play Store opt-in link can tap through without typing anything and
    // land on a working demo immediately. The hard-coded fallbacks below ship the GitHub-
    // Pages demo so the fields are never blank, even when .env values get stripped by the
    // bundler. Override at build time with the SOURCE_* env vars to point a shipped build
    // at a different default.
    'api_url_default' => env('SOURCE_API_URL', 'https://lajennylove.github.io/readex/api'),
    'covers_url_default' => env('SOURCE_COVERS_URL', 'https://lajennylove.github.io/readex/covers'),
    'seasonal_url_default' => env('SOURCE_SEASONAL_URL', 'https://lajennylove.github.io/readex/seasonal/seasonal-list.json'),

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
