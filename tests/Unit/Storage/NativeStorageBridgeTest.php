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
