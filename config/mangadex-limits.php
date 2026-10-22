<?php

/*
| Copy of https://api.mangadex.org/docs/2-limitations/ (checked 2026-10-22), every row,
| including endpoints v1 never calls. "capacity" is our budget, "limit" is MangaDex's
| documented number; header sync scales the budget by capacity/limit when MangaDex
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

    // Endpoint rules for api.mangadex.org. First match wins. {id} matches one path segment.
    'rules' => [
        ['GET', '/at-home/server/{id}', 'at-home'],
        ['GET', '/manga/random', 'random'],
        ['POST', '/auth/login', 'auth-login'],
        ['POST', '/auth/refresh', 'auth-refresh'],
        ['POST', '/author', 'author-create'],
        ['PUT', '/author/{id}', 'author-update'],
        ['DELETE', '/author/{id}', 'author-delete'],
        ['POST', '/captcha/solve', 'captcha-solve'],
        ['POST', '/cover', 'cover-create'],
        ['POST', '/cover/{id}', 'cover-create'],
        ['PUT', '/cover/{id}', 'cover-update'],
        ['DELETE', '/cover/{id}', 'cover-delete'],
        ['POST', '/chapter/{id}/read', 'chapter-read'],
        ['PUT', '/chapter/{id}', 'chapter-update'],
        ['DELETE', '/chapter/{id}', 'chapter-delete'],
        ['POST', '/forums/thread', 'forums-thread'],
        ['POST', '/manga/draft/{id}/commit', 'manga-draft-commit'],
        ['POST', '/manga', 'manga-create'],
        ['PUT', '/manga/{id}', 'manga-update'],
        ['DELETE', '/manga/{id}', 'manga-delete'],
        ['POST', '/report', 'report-create'],
        ['GET', '/report', 'report-list'],
        ['POST', '/group', 'group-create'],
        ['PUT', '/group/{id}', 'group-update'],
        ['DELETE', '/group/{id}', 'group-delete'],
        ['GET', '/upload', 'upload-list'],
        ['POST', '/upload/begin', 'upload-begin'],
        ['POST', '/upload/begin/{id}', 'upload-begin'],
        ['POST', '/upload/{id}/commit', 'upload-commit'],
        ['DELETE', '/upload/{id}/batch', 'upload-files'],
        ['DELETE', '/upload/{id}/{id}', 'upload-files'],
        ['DELETE', '/upload/{id}', 'upload-abandon'],
        ['POST', '/upload/{id}', 'upload-files'],
    ],

    // Host => [host bucket, family]. A family is blocked as a whole on 429 / 403 ban.
    'hosts' => [
        'api.mangadex.org' => ['global', 'mangadex.org'],
        'uploads.mangadex.org' => ['covers', 'mangadex.org'],
        'api.mangadex.network' => ['report', null],
        '*' => ['images', null],
    ],

    // Extra bucket a prefetch/bulk call must also take, so the user's own screen keeps headroom.
    'background' => [
        'global' => 'global:background',
        'at-home' => 'at-home:background',
    ],

];
