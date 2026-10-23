<?php

use App\Storage\LocalStorageBridge;
use App\Storage\NativeStorageBridge;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    $this->local = new LocalStorageBridge(sys_get_temp_dir().'/mdx-test-'.uniqid());
});

it('reads the preferred language from the plugin', function () {
    Native::fakeBridge()->respondTo('MdxStorage.PreferredLanguage', ['language' => 'fr-CA']);

    expect((new NativeStorageBridge($this->local))->preferredLanguage())->toBe('fr-CA');
});

it('accepts a response wrapped in data', function () {
    Native::fakeBridge()->respondTo('MdxStorage.CachesPath', ['status' => 'success', 'data' => ['path' => '/var/caches/readex']]);

    expect((new NativeStorageBridge($this->local))->cachesPath())->toBe('/var/caches/readex');
});

it('asks the plugin for the caches path once per process', function () {
    $fake = Native::fakeBridge()->respondTo('MdxStorage.CachesPath', ['path' => '/var/caches/readex']);
    $bridge = new NativeStorageBridge($this->local);

    foreach (range(1, 5) as $_) {
        expect($bridge->cachesPath())->toBe('/var/caches/readex');
    }

    $fake->assertCalledTimes('MdxStorage.CachesPath', 1);
});

it('asks once for the caches path under Jump too, where the plugin has no answer', function () {
    $fake = Native::fakeBridge();
    $bridge = new NativeStorageBridge($this->local);

    $bridge->cachesPath();
    $bridge->cachesPath();

    $fake->assertCalledTimes('MdxStorage.CachesPath', 1);
});

it('falls back to the local bridge when the plugin has no answer (Jump)', function () {
    Native::fakeBridge();

    $bridge = new NativeStorageBridge($this->local);

    expect($bridge->preferredLanguage())->toBeNull()
        ->and($bridge->cachesPath())->toBe($this->local->cachesPath())
        ->and($bridge->excludeFromBackup('/tmp/x'))->toBeFalse();
});

it('falls back when the plugin returns an error', function () {
    Native::fakeBridge()->respondTo('MdxStorage.ExcludeFromBackup', ['status' => 'error', 'code' => 'invalid_path', 'message' => 'path is required']);

    expect((new NativeStorageBridge($this->local))->excludeFromBackup(''))->toBeFalse();
});

it('reads the phone time zone from the plugin', function () {
    Native::fakeBridge()->respondTo('MdxStorage.TimeZone', ['zone' => 'America/Toronto']);

    expect((new NativeStorageBridge($this->local))->timeZone())->toBe('America/Toronto');
});

it('falls back to the local zone when the plugin has none or sends nonsense', function (?array $answer) {
    $fake = Native::fakeBridge();
    if ($answer !== null) {
        $fake->respondTo('MdxStorage.TimeZone', $answer);
    }

    expect((new NativeStorageBridge($this->local))->timeZone())->toBe($this->local->timeZone());
})->with([[null], [['zone' => 'Mars/Olympus']], [['zone' => 42]]]);

it('gives a real zone on the Mac', function () {
    expect(in_array($this->local->timeZone(), timezone_identifiers_list(DateTimeZone::ALL_WITH_BC), true))->toBeTrue();
});
