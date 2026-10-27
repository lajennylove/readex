<?php

return [

    // Ships pointed at the GitHub Pages demo so the first launch has something to render.
    // The user swaps this out at Setup for whatever source URL they actually want to read
    // from — the app is a transport-and-render tool, not a catalog (CLAUDE.md §1).
    'api_url' => env('SOURCE_API_URL', 'https://lajennylove.github.io/readex/api'),

    // Build-time hint for sources that serve plain JSON files (the demo does). When true,
    // Client::apiUrl() rewrites `/path` → `/path.json` before appending the query.
    'static_json' => (bool) env('SOURCE_STATIC_JSON', true),

    // Where image-fetch reports go. Empty is a valid value (no reports). The demo serves
    // one at /report that accepts and discards; a real upstream wires its own endpoint.
    'report_url' => env('SOURCE_REPORT_URL', 'https://lajennylove.github.io/readex/report'),

    // Legacy fallback: used only when a source's API response doesn't include an absolute
    // cover URL. The demo (and any truly agnostic source) embeds the full image URL in
    // `cover_art.attributes.fileName` and CoverStore::url() returns it verbatim — this
    // concat path is kept for upstreams that still return a bare filename.
    'covers_url' => env('SOURCE_COVERS_URL', 'https://lajennylove.github.io/readex/covers'),

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

    // Hostname family the configured source serves from. Used by Client::isSourceOwnHost
    // to decide whether an image URL came from the source itself (so no inline report).
    // Empty = treat every image URL as off-source.
    'status_family' => env('SOURCE_STATUS_FAMILY', ''),

    // Path (relative to the API root) at which a source may publish an optional metadata
    // document: {covers_url, report_url, status_family, static_json}. SourceRepository
    // fetches it once at setup and AppServiceProvider swaps the values into runtime config.
    'meta_path' => env('SOURCE_META_PATH', '/_meta.json'),

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
