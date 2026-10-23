<?php

use App\Storage\CacheLink;
use App\Storage\LocalStorageBridge;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/mdx-link-'.uniqid();
    config(['filesystems.disks.public.root' => $this->root.'/public']);
    mkdir($this->root.'/public', 0755, true);
});

it('links storage/app/public/cache to the caches folder', function () {
    $bridge = new LocalStorageBridge($this->root.'/a');

    $target = CacheLink::ensure($bridge);

    expect(is_link($this->root.'/public/cache'))->toBeTrue()
        ->and(readlink($this->root.'/public/cache'))->toBe($target)
        ->and($target)->toBe($bridge->cachesPath());
});

it('re-points the link when the caches folder moved (app update)', function () {
    CacheLink::ensure(new LocalStorageBridge($this->root.'/old'));
    $new = new LocalStorageBridge($this->root.'/new');

    CacheLink::ensure($new);

    expect(readlink($this->root.'/public/cache'))->toBe($new->cachesPath());
});

it('replaces a real folder left at the link path (build copy follows symlinks)', function () {
    mkdir($this->root.'/public/cache/sub', 0755, true);
    file_put_contents($this->root.'/public/cache/sub/page.jpg', 'x');
    $bridge = new LocalStorageBridge($this->root.'/a');

    CacheLink::ensure($bridge);

    expect(is_link($this->root.'/public/cache'))->toBeTrue()
        ->and(readlink($this->root.'/public/cache'))->toBe($bridge->cachesPath());
});
