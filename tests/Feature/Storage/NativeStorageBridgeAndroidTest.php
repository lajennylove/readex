<?php

use App\Storage\LocalStorageBridge;
use App\Storage\NativeStorageBridge;
use Native\Mobile\Testing\Native;

/*
 * Android contract test for the MdxStorage bridge.
 *
 * NativeStorageBridge does not branch on platform — the bridge method names it dispatches
 * (`MdxStorage.PreferredLanguage`, `MdxStorage.TimeZone`, `MdxStorage.CachesPath`,
 * `MdxStorage.ExcludeFromBackup`) are the same on iOS and Android. This test pins those
 * names so that if PHP ever drifts from the names the Android Kotlin plugin registers
 * (packages/lajennylove/plugin-mdx-storage/resources/android/MdxStorageFunctions.kt),
 * the mismatch is caught here rather than at runtime on-device.
 *
 * Kotlin itself is not exercised — Pest can't load a JVM. The point is: the PHP side's
 * expectations for the Android plugin are codified.
 */

beforeEach(function () {
    $this->local = new LocalStorageBridge(sys_get_temp_dir().'/mdx-android-test-'.uniqid());
});

it('dispatches the four MdxStorage bridge names the Android Kotlin plugin registers', function () {
    $fake = Native::fakeBridge()
        ->respondTo('MdxStorage.PreferredLanguage', ['language' => 'fr-FR'])
        ->respondTo('MdxStorage.TimeZone', ['zone' => 'Europe/Paris'])
        ->respondTo('MdxStorage.CachesPath', ['path' => '/data/user/0/com.readex/cache/readex'])
        ->respondTo('MdxStorage.ExcludeFromBackup', ['excluded' => true]);

    $bridge = new NativeStorageBridge($this->local);

    expect($bridge->preferredLanguage())->toBe('fr-FR')
        ->and($bridge->timeZone())->toBe('Europe/Paris')
        ->and($bridge->cachesPath())->toBe('/data/user/0/com.readex/cache/readex')
        ->and($bridge->excludeFromBackup('/data/user/0/com.readex/cache/readex/x'))->toBeTrue();

    $fake->assertCalled('MdxStorage.PreferredLanguage')
        ->assertCalled('MdxStorage.TimeZone')
        ->assertCalled('MdxStorage.CachesPath')
        ->assertCalled('MdxStorage.ExcludeFromBackup');
});

it('passes the path parameter to MdxStorage.ExcludeFromBackup the Android no-op expects', function () {
    $fake = Native::fakeBridge()
        ->respondTo('MdxStorage.ExcludeFromBackup', ['excluded' => true]);

    (new NativeStorageBridge($this->local))->excludeFromBackup('/data/user/0/com.readex/cache/readex/covers');

    $fake->assertCalled(
        'MdxStorage.ExcludeFromBackup',
        fn (array $params): bool => ($params['path'] ?? null) === '/data/user/0/com.readex/cache/readex/covers'
    );
});
