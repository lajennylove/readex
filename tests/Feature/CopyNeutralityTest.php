<?php

use Illuminate\Support\Facades\File;

/**
 * Plan-08 Task 6 (D45 §2/§5.4): a standing guard, not just a one-time review. Scans every
 * user-facing surface — Blade views AND the NativeComponent / NativeLayout PHP classes that
 * set screen titles, labels and placeholders from the PHP side — for well-known non-MangaDex
 * manga aggregator / piracy site names. A reviewer-visible string that lived in a Native
 * component class would bypass a views-only scan, so both roots are checked. See
 * `docs/COPY-NEUTRALITY.md` for the policy this test enforces.
 */
it('never names a specific non-MangaDex manga site anywhere a user could see it', function () {
    $banned = [
        'mangakakalot', 'manganato', 'mangafire', 'mangareader', 'bato.to', 'batotoo',
        'asurascans', 'asura scans', 'webtoons.com', 'tapas.io', 'mangaplus', 'mangadex.cc',
        'mangapark', 'mangago', 'mangaowl', 'mangahere', 'mangahub', 'mangasee',
        'toonily', 'reaperscans', 'flamecomics', 'comick.io', 'comick.app',
    ];

    $roots = [
        resource_path('views/native'),
        app_path('NativeComponents'),
        app_path('NativeLayouts'),
    ];

    $offenders = [];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        foreach (File::allFiles($root) as $file) {
            if ($file->getExtension() === 'php' && str_contains($file->getPathname(), '/tests/')) {
                continue;
            }
            $contents = mb_strtolower(file_get_contents($file->getPathname()));

            foreach ($banned as $name) {
                if (str_contains($contents, $name)) {
                    $offenders[] = "{$file->getRelativePathname()} mentions \"{$name}\"";
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('describes ReaDex as a generic reading client, source-neutral, in the Sources screen copy', function () {
    $contents = file_get_contents(resource_path('views/native/sources.blade.php'));

    expect($contents)->toContain('reading client')
        ->and($contents)->toContain('your own content sources');
});
