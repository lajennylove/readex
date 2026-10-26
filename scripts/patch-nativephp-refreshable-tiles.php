<?php

declare(strict_types=1);

/**
 * ReaDex post-install patch: replace LazyColumn with a verticalScroll Column
 * inside NativePHP Mobile UI's Android RefreshableRenderer.
 *
 * The vendor wraps a <native:refreshable> screen's children in a Compose
 * `LazyColumn`. On ReaDex's Library / Home / Search / MangaDetail screens the
 * refreshable's one child is a plain <native:column> that in turn contains
 * horizontally-scrolling rows of <native:pressable> tiles. LazyColumn's
 * pointer-input handling, combined with PullToRefreshBox's nested-scroll
 * connection above it, swallows the pressable tap events on Android: no
 * BridgeJNI press event is ever emitted, so openManga() / openChapter() never
 * fire and the user sees "nothing happens" when tapping a tile.
 *
 * We don't actually need LazyColumn's item-recycling behaviour on these
 * screens (the Library and Home trees are small), and iOS doesn't use a lazy
 * list for Refreshable either. Switching to a regular Column with
 * `rememberScrollState().verticalScroll` restores standard Compose
 * pointer-input flow, which lets Pressable's press-release detector see the
 * taps again.
 *
 * Composer runs this after every install/update so a vendor refresh doesn't
 * silently reintroduce the bug.
 */
$targets = [
    __DIR__.'/../vendor/nativephp/mobile-ui/resources/android/RefreshableRenderer.kt',
    __DIR__.'/../nativephp/android/app/src/nativephp/kotlin/com/nativephp/plugins/native_ui/ui/RefreshableRenderer.kt',
];

$needle = <<<'KOTLIN'
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
KOTLIN;

$needleReplacement = <<<'KOTLIN'
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.runtime.key
KOTLIN;

$lazyColumnBlock = <<<'KOTLIN'
            LazyColumn {
                items(node.children, key = { it.id }) { child ->
                    NodeView(node = child)
                }
            }
KOTLIN;

$columnBlock = <<<'KOTLIN'
            // Patched for ReaDex: see scripts/patch-nativephp-refreshable-tiles.php
            Column(modifier = Modifier.verticalScroll(rememberScrollState())) {
                node.children.forEach { child ->
                    key(child.id) {
                        NodeView(node = child)
                    }
                }
            }
KOTLIN;

foreach ($targets as $target) {
    if (! is_file($target)) {
        fwrite(STDERR, "patch-nativephp-refreshable-tiles: file missing — {$target}\n");

        continue;
    }

    $contents = file_get_contents($target);

    if (str_contains($contents, 'Patched for ReaDex: see scripts/patch-nativephp-refreshable-tiles.php')) {
        echo "patch-nativephp-refreshable-tiles: already patched — {$target}\n";

        continue;
    }

    if (! str_contains($contents, $needle) || ! str_contains($contents, $lazyColumnBlock)) {
        fwrite(STDERR, "patch-nativephp-refreshable-tiles: anchor not found in {$target}\n");

        continue;
    }

    $patched = str_replace($needle, $needleReplacement, $contents);
    $patched = str_replace($lazyColumnBlock, $columnBlock, $patched);

    file_put_contents($target, $patched);
    echo "patch-nativephp-refreshable-tiles: patched {$target}\n";
}
