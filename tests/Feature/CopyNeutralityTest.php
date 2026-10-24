<?php

use Illuminate\Support\Facades\File;

/**
 * Plan-08 Task 6 (D45 §2/§5.4): a standing guard, not just a one-time review. Scans every
 * Blade view for any of the well-known non-MangaDex manga aggregator/piracy site names, so a
 * future screen can't accidentally reintroduce one. New sources are user-added only (§2); the
 * app itself must never name, suggest, or auto-discover one.
 */
it('never names a specific non-MangaDex manga site anywhere in the views', function () {
    $banned = [
        'mangakakalot', 'manganato', 'mangafire', 'mangareader', 'bato.to', 'batotoo',
        'asurascans', 'asura scans', 'webtoons.com', 'tapas.io', 'mangaplus', 'mangadex.cc',
        'mangapark', 'mangago', 'mangaowl', 'mangahere', 'mangahub', 'mangasee',
        'toonily', 'reaperscans', 'flamecomics', 'comick.io', 'comick.app',
    ];

    $views = File::allFiles(resource_path('views/native'));
    $offenders = [];

    foreach ($views as $view) {
        $contents = mb_strtolower(file_get_contents($view->getPathname()));

        foreach ($banned as $name) {
            if (str_contains($contents, $name)) {
                $offenders[] = "{$view->getRelativePathname()} mentions \"{$name}\"";
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
