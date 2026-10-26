<?php

/*
| Copy of the source's documented rate limits (checked 2026-10-22), every row,
| including endpoints v1 never calls. "capacity" is our budget, "limit" is the source's
| documented number; header sync scales the budget by capacity/limit when the source
| reports a different limit.
*/

return [

    'buckets' => [
        'global' => ['capacity' => 4, 'per_seconds' => 1, 'adaptive' => true],
        'global:background' => ['capacity' => 3, 'per_seconds' => 1, 'adaptive' => true],

        'at-home' => ['capacity' => 38, 'per_seconds' => 60, 'limit' => 40],
        'at-home:background' => ['capacity' => 30, 'per_seconds' => 60],
        'random' => ['capacity' => 55, 'per_seconds' => 60, 'limit' => 60],

        'auth-login' => ['capacity' => 27, 'per_seconds' => 3600, 'limit' => 30],
        'auth-refresh' => ['capacity' => 54, 'per_seconds' => 3600, 'limit' => 60],
        'author-create' => ['capacity' => 9, 'per_seconds' => 3600, 'limit' => 10],
        'author-update' => ['capacity' => 9, 'per_seconds' => 60, 'limit' => 10],
        'author-delete' => ['capacity' => 9, 'per_seconds' => 600, 'limit' => 10],
        'captcha-solve' => ['capacity' => 9, 'per_seconds' => 600, 'limit' => 10],
        'cover-create' => ['capacity' => 90, 'per_seconds' => 600, 'limit' => 100],
        'cover-update' => ['capacity' => 90, 'per_seconds' => 600, 'limit' => 100],
        'cover-delete' => ['capacity' => 9, 'per_seconds' => 600, 'limit' => 10],
        'chapter-read' => ['capacity' => 270, 'per_seconds' => 600, 'limit' => 300],
        'chapter-update' => ['capacity' => 9, 'per_seconds' => 60, 'limit' => 10],
        'chapter-delete' => ['capacity' => 9, 'per_seconds' => 60, 'limit' => 10],
        'forums-thread' => ['capacity' => 9, 'per_seconds' => 60, 'limit' => 10],
        'manga-create' => ['capacity' => 9, 'per_seconds' => 3600, 'limit' => 10],
        'manga-update' => ['capacity' => 9, 'per_seconds' => 3600, 'limit' => 10],
        'manga-delete' => ['capacity' => 9, 'per_seconds' => 600, 'limit' => 10],
        'manga-draft-commit' => ['capacity' => 9, 'per_seconds' => 3600, 'limit' => 10],
        'report-create' => ['capacity' => 9, 'per_seconds' => 60, 'limit' => 10],
        'report-list' => ['capacity' => 9, 'per_seconds' => 60, 'limit' => 10],
        'group-create' => ['capacity' => 9, 'per_seconds' => 3600, 'limit' => 10],
        'group-update' => ['capacity' => 9, 'per_seconds' => 60, 'limit' => 10],
        'group-delete' => ['capacity' => 9, 'per_seconds' => 600, 'limit' => 10],
        'upload-list' => ['capacity' => 27, 'per_seconds' => 60, 'limit' => 30],
        'upload-begin' => ['capacity' => 18, 'per_seconds' => 60, 'limit' => 20],
        'upload-commit' => ['capacity' => 9, 'per_seconds' => 60, 'limit' => 10],
        'upload-abandon' => ['capacity' => 27, 'per_seconds' => 60, 'limit' => 30],
        'upload-files' => ['capacity' => 225, 'per_seconds' => 60, 'limit' => 250],

        'covers' => ['capacity' => 4, 'per_seconds' => 1],
        'images' => ['capacity' => 8, 'per_seconds' => 1],
        'report' => ['capacity' => 2, 'per_seconds' => 1],
    ],

    // Endpoint rules for the source API. First match wins. {id} matches one path segment.
    'rules' => [
        // feature/0035: same-origin covers + report, matched by path rather than host.
        ['GET', '/covers/{id}/{id}', 'covers'],
        ['POST', '/report', 'report'],

        ['GET', '/api/at-home/server/{id}', 'at-home'],
        ['GET', '/api/manga/random', 'random'],
        ['POST', '/api/auth/login', 'auth-login'],
        ['POST', '/api/auth/refresh', 'auth-refresh'],
        ['POST', '/api/author', 'author-create'],
        ['PUT', '/api/author/{id}', 'author-update'],
        ['DELETE', '/api/author/{id}', 'author-delete'],
        ['POST', '/api/captcha/solve', 'captcha-solve'],
        ['POST', '/api/cover', 'cover-create'],
        ['POST', '/api/cover/{id}', 'cover-create'],
        ['PUT', '/api/cover/{id}', 'cover-update'],
        ['DELETE', '/api/cover/{id}', 'cover-delete'],
        ['POST', '/api/chapter/{id}/read', 'chapter-read'],
        ['PUT', '/api/chapter/{id}', 'chapter-update'],
        ['DELETE', '/api/chapter/{id}', 'chapter-delete'],
        ['POST', '/api/forums/thread', 'forums-thread'],
        ['POST', '/api/manga/draft/{id}/commit', 'manga-draft-commit'],
        ['POST', '/api/manga', 'manga-create'],
        ['PUT', '/api/manga/{id}', 'manga-update'],
        ['DELETE', '/api/manga/{id}', 'manga-delete'],
        ['POST', '/api/report', 'report-create'],
        ['GET', '/api/report', 'report-list'],
        ['POST', '/api/group', 'group-create'],
        ['PUT', '/api/group/{id}', 'group-update'],
        ['DELETE', '/api/group/{id}', 'group-delete'],
        ['GET', '/api/upload', 'upload-list'],
        ['POST', '/api/upload/begin', 'upload-begin'],
        ['POST', '/api/upload/begin/{id}', 'upload-begin'],
        ['POST', '/api/upload/{id}/commit', 'upload-commit'],
        ['DELETE', '/api/upload/{id}/batch', 'upload-files'],
        ['DELETE', '/api/upload/{id}/{id}', 'upload-files'],
        ['DELETE', '/api/upload/{id}', 'upload-abandon'],
        ['POST', '/api/upload/{id}', 'upload-files'],
    ],

    // Host => [host bucket, family]. feature/0035: ReaDex is agnostic — the api,
    // covers and report endpoints all live on the same user-configured host, so
    // the per-host entries went away. '*' is the fallback for images fetched from
    // the @Home network (whatever hosts the source hands back).
    'hosts' => [
        '*' => ['images', null],
    ],

    // Extra bucket a prefetch/bulk call must also take, so the user's own screen keeps headroom.
    'background' => [
        'global' => 'global:background',
        'at-home' => 'at-home:background',
    ],

];
