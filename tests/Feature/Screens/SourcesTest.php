<?php

use App\Models\SourceConfig;
use App\NativeComponents\Sources;
use App\Repositories\SourceRepository;
use App\Sources\MangaDexSource;
use Illuminate\Support\Carbon;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    Carbon::setTestNow('2026-10-27 12:00:00');
});

it('lists MangaDex built in, never removable, with a block toggle', function () {
    Native::visit('/settings/sources')
        ->assertScreen(Sources::class)
        ->assertSee('MangaDex')
        ->assertSee('Built in')
        ->assertDontSee('Remove');
});

it('lists a user-added source with its URL and a Remove action', function () {
    app(SourceRepository::class)->add('My Source', 'https://example.test/api');

    Native::visit('/settings/sources')
        ->assertSee('My Source')
        ->assertSee('https://example.test/api')
        ->assertSee('Remove');
});

it('adds a source from the name + URL fields', function () {
    Native::test(Sources::class)
        ->call('changeLabel', 'My Source')
        ->call('changeUrl', 'https://example.test/api')
        ->call('addSource')
        ->assertSee('My Source')
        ->assertSet('newLabel', '')
        ->assertSet('newUrl', '');

    expect(SourceConfig::query()->where('label', 'My Source')->exists())->toBeTrue();
});

it('shows an error and keeps the fields when the URL is invalid', function () {
    Native::test(Sources::class)
        ->call('changeLabel', 'My Source')
        ->call('changeUrl', 'not-a-url')
        ->call('addSource')
        ->assertSee('Not a valid URL.')
        ->assertSet('newLabel', 'My Source');
});

it('blocks and unblocks a source from its toggle', function () {
    Native::test(Sources::class)->call('toggleBlocked', MangaDexSource::KEY, true);

    expect(app(SourceRepository::class)->find(MangaDexSource::KEY)->blocked)->toBeTrue();

    Native::test(Sources::class)->call('toggleBlocked', MangaDexSource::KEY, false);
    expect(app(SourceRepository::class)->find(MangaDexSource::KEY)->blocked)->toBeFalse();
});

it('removes a user source after confirming, keeps it if cancelled', function () {
    $source = app(SourceRepository::class)->add('My Source', 'https://example.test');

    Native::test(Sources::class)->call('askRemove', $source->key)
        ->emitNative(ButtonPressed::class, ['index' => 0, 'label' => 'Cancel']);
    expect(SourceConfig::query()->find($source->key))->not->toBeNull();

    Native::test(Sources::class)->call('askRemove', $source->key)
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => Sources::REMOVE]);
    expect(SourceConfig::query()->find($source->key))->toBeNull();
});
