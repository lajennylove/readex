<?php

declare(strict_types=1);

/**
 * ReaDex post-install patch: teach NativePHP Mobile's AndroidPluginCompiler
 * that `android_params: []` means a Kotlin bridge function class takes NO
 * constructor argument.
 *
 * The vendor's `determineParameter()` fell back to 'activity' when the params
 * array was empty, which emitted `MyFunc(activity)` for classes with no-arg
 * constructors (e.g. our `MdxStorageFunctions.TimeZone` and
 * `.ExcludeFromBackup`) and broke the Kotlin build on every rebuild.
 *
 * Composer runs this after every install/update so a vendor refresh doesn't
 * silently reintroduce the bug.
 */
$target = __DIR__.'/../vendor/nativephp/mobile/src/Plugins/Compilers/AndroidPluginCompiler.php';

if (! is_file($target)) {
    return;
}

$contents = file_get_contents($target);

if (str_contains($contents, '// Patched for ReaDex: an explicitly empty `android_params: []`')) {
    echo "patch-nativephp-android-compiler: already patched\n";

    return;
}

$needle = <<<'PHP'
    protected function determineParameter(array $params): string
    {
        if (in_array('activity', $params)) {
PHP;

$replacement = <<<'PHP'
    protected function determineParameter(array $params): string
    {
        // Patched for ReaDex: an explicitly empty `android_params: []` in a
        // plugin's nativephp.json means the Kotlin class takes no constructor
        // argument. Without this short-circuit, the compiler fell back to
        // 'activity' and emitted `MyFunc(activity)` for classes with a no-arg
        // ctor, breaking the Kotlin build.
        if ($params === []) {
            return '';
        }

        if (in_array('activity', $params)) {
PHP;

if (! str_contains($contents, $needle)) {
    fwrite(STDERR, "patch-nativephp-android-compiler: anchor not found\n");

    return;
}

file_put_contents($target, str_replace($needle, $replacement, $contents));
echo "patch-nativephp-android-compiler: patched {$target}\n";
