<?php

use Illuminate\Support\Facades\File;

/**
 * Plan-08 Task 6 (D45 §2/§5.4) + Plan-08b Task 6: a standing guard, not just a one-time review.
 * The first scan covers every user-facing surface — Blade views AND the NativeComponent /
 * NativeLayout PHP classes that set screen titles, labels and placeholders from the PHP side —
 * for well-known manga aggregator / piracy site names. A reviewer-visible string that lived in
 * a Native component class would bypass a views-only scan, so both roots are checked. The second
 * scan widens the net to the full `app/` tree, catching brand references anywhere in the
 * codebase, with a tightly-scoped per-file exemption list for identifiers that genuinely cannot
 * be renamed (persisted DB values, HTTP host literals used for rate-limit routing, API-shape
 * method names). See `docs/COPY-NEUTRALITY.md` for the policy this test enforces.
 */

/** The brand-name banned list — shared by both scans. "mangadex" is on it: the T6 guard-flip
 * assumes the generic-rename pass has landed (Plan-08b T1–T5). */
function copyNeutralityBannedList(): array
{
    return [
        'mangadex',
        'mangakakalot', 'manganato', 'mangafire', 'mangareader', 'bato.to', 'batotoo',
        'asurascans', 'asura scans', 'webtoons.com', 'tapas.io', 'mangaplus', 'mangadex.cc',
        'mangapark', 'mangago', 'mangaowl', 'mangahere', 'mangahub', 'mangasee',
        'toonily', 'reaperscans', 'flamecomics', 'comick.io', 'comick.app',
    ];
}

it('never names a specific manga site anywhere a user could see it', function () {
    $banned = copyNeutralityBannedList();

    // Same identifier-level exemptions as the broader app/ scan: these are not brand
    // references, they are the `IosLanguageMap::toMangaDex()` method name describing the API's
    // language-code format. Still called from Setup.php; renaming it is a separate task.
    $exemptions = [
        'Setup.php' => ['toMangaDex'],
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
            $exempt = $exemptions[$file->getRelativePathname()] ?? [];
            $raw = file_get_contents($file->getPathname());
            $lower = mb_strtolower($raw);

            foreach ($banned as $name) {
                if (! str_contains($lower, $name)) {
                    continue;
                }

                $isExempt = false;
                foreach ($exempt as $substr) {
                    if (str_contains($raw, $substr)) {
                        $isExempt = true;
                        break;
                    }
                }

                if (! $isExempt) {
                    $offenders[] = "{$file->getRelativePathname()} mentions \"{$name}\"";
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('never names a specific non-MangaDex content site anywhere in app/ (code identifiers aside)', function () {
    $banned = copyNeutralityBannedList();

    // Lines in these files that contain these substrings are exempt — they are not brand
    // references. They are: a persisted DB identifier (sources.key = "mangadex" at rest in
    // SQLite), HTTP host literals used by the rate-limit router to tally per-host budgets,
    // and semantically-specific method names describing the API's language-code format.
    // New exemptions require explicit sign-off; see docs/COPY-NEUTRALITY.md.
    $exemptions = [
        'app/Sources/HttpSource.php' => ["KEY = 'mangadex'"],
        'app/Adapters/Http/Client.php' => ['isMangaDexHost', "'mangadex.org'", "'.mangadex.org'"],
        'app/Language/IosLanguageMap.php' => ['toMangaDex'],
        'app/Language/LanguageChain.php' => ['toMangaDex'],
        'app/NativeComponents/Setup.php' => ['toMangaDex'],
    ];

    $files = File::allFiles(app_path());
    $offenders = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $rel = str_replace(base_path().'/', '', $file->getPathname());
        $exempt = $exemptions[$rel] ?? [];

        $lines = file($file->getPathname());

        foreach ($lines as $i => $line) {
            $lower = mb_strtolower($line);

            foreach ($banned as $name) {
                if (! str_contains($lower, $name)) {
                    continue;
                }

                $isExempt = false;
                foreach ($exempt as $substr) {
                    if (str_contains($line, $substr)) {
                        $isExempt = true;
                        break;
                    }
                }

                if (! $isExempt) {
                    $offenders[] = "{$rel}:".($i + 1)." \"{$name}\" in line: ".trim($line);
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
