<?php

declare(strict_types=1);

/**
 * ReaDex post-install patch: shorten NativePHP Mobile's async executor start
 * delay from 2500ms to 100ms.
 *
 * The vendor default meant the Android host's AsyncTaskExecutor booted ~2.5s
 * after Laravel did. Any AsyncTask::dispatch() fired during that window — and
 * ReaDex fires several on Home/Library's first render for cover fetches and
 * interactive page reads — was dropped with "no async executor running", which
 * PHP surfaced to users as the "Something went wrong" banner plus broken
 * covers. Starting ASAP is safe: pool threads boot their PHP context lazily on
 * first task, so there's no cost until work is dispatched.
 *
 * Composer runs this after every install/update so a vendor refresh doesn't
 * silently reintroduce the bug.
 */
$targets = [
    __DIR__.'/../vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt',
    __DIR__.'/../nativephp/android/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt',
];

$needle = 'private const val WORKER_START_DELAY_MS = 2500L';
$replacement = <<<'KOTLIN'
// Patched for ReaDex: see scripts/patch-nativephp-executor-delay.php
        private const val WORKER_START_DELAY_MS = 100L
KOTLIN;

foreach ($targets as $target) {
    if (! is_file($target)) {
        continue;
    }

    $contents = file_get_contents($target);

    if (str_contains($contents, 'WORKER_START_DELAY_MS = 100L')) {
        echo "patch-nativephp-executor-delay: already patched — {$target}\n";

        continue;
    }

    if (! str_contains($contents, $needle)) {
        fwrite(STDERR, "patch-nativephp-executor-delay: anchor not found in {$target}\n");

        continue;
    }

    file_put_contents($target, str_replace($needle, $replacement, $contents));
    echo "patch-nativephp-executor-delay: patched {$target}\n";
}
