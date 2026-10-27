<?php

use App\Models\SourceConfig;
use App\NativeComponents\Sources;
use App\Repositories\SourceRepository;
use App\Sources\HttpSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    Carbon::setTestNow('2026-10-27 12:00:00');
});

it('lists the built-in example source, never removable, with a block toggle', function () {
    Native::visit('/settings/sources')
        ->assertScreen(Sources::class)
        ->assertSee('Built-in example source')
        ->assertSee('Built in')
        ->assertDontSee('Remove');
});

it('lists a user-added source with its URL and Edit/Remove actions', function () {
    Http::fake(['https://example.test/api' => Http::response('', 200)]);
    app(SourceRepository::class)->add('My Source', 'https://example.test/api');

    Native::visit('/settings/sources')
        ->assertSee('My Source')
        ->assertSee('https://example.test/api')
        ->assertSee('Edit URL')
        ->assertSee('Remove');
});

it('adds a source from the name + URL fields once it health-checks ok', function () {
    Http::fake(['https://example.test/api' => Http::response('', 200)]);

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

it('shows an error and keeps the fields when the URL fails its health check', function () {
    Http::fake(['https://example.test/down' => Http::response('', 503)]);

    Native::test(Sources::class)
        ->call('changeLabel', 'My Source')
        ->call('changeUrl', 'https://example.test/down')
        ->call('addSource')
        ->assertSee("Couldn't connect to that source. Check the URL and try again.")
        ->assertSet('newLabel', 'My Source');

    expect(SourceConfig::query()->where('label', 'My Source')->exists())->toBeFalse();
});

it('blocks and unblocks a source from its toggle', function () {
    Native::test(Sources::class)->call('toggleBlocked', HttpSource::KEY, true);

    expect(app(SourceRepository::class)->find(HttpSource::KEY)->blocked)->toBeTrue();

    Native::test(Sources::class)->call('toggleBlocked', HttpSource::KEY, false);
    expect(app(SourceRepository::class)->find(HttpSource::KEY)->blocked)->toBeFalse();
});

it('removes a user source after confirming, keeps it if cancelled', function () {
    Http::fake(['https://example.test' => Http::response('', 200)]);
    $source = app(SourceRepository::class)->add('My Source', 'https://example.test');

    Native::test(Sources::class)->call('askRemove', $source->key)
        ->emitNative(ButtonPressed::class, ['index' => 0, 'label' => 'Cancel']);
    expect(SourceConfig::query()->find($source->key))->not->toBeNull();

    Native::test(Sources::class)->call('askRemove', $source->key)
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => Sources::REMOVE]);
    expect(SourceConfig::query()->find($source->key))->toBeNull();
});

it('edits a user source URL after a health check (Jenny 2026-10-24 18:05 EDT)', function () {
    Http::fake(['https://a.test' => Http::response('', 200), 'https://b.test' => Http::response('', 200)]);
    $source = app(SourceRepository::class)->add('My Source', 'https://a.test');

    Native::test(Sources::class)
        ->call('startEdit', $source->key)
        ->assertSet('editing', $source->key)
        ->assertSet('editUrl', 'https://a.test')
        ->call('changeEditUrl', 'https://b.test')
        ->call('saveEdit')
        ->assertSet('editing', null);

    expect(app(SourceRepository::class)->find($source->key)->config['base_url'])->toBe('https://b.test');
});

it('shows an error and stays in edit mode when the new URL fails its health check', function () {
    Http::fake(['https://a.test' => Http::response('', 200), 'https://down.test' => Http::response('', 503)]);
    $source = app(SourceRepository::class)->add('My Source', 'https://a.test');

    Native::test(Sources::class)
        ->call('startEdit', $source->key)
        ->call('changeEditUrl', 'https://down.test')
        ->call('saveEdit')
        ->assertSet('editing', $source->key)
        ->assertSee("Couldn't connect to that source. Check the URL and try again.");

    expect(app(SourceRepository::class)->find($source->key)->config['base_url'])->toBe('https://a.test');
});

it('cancelling edit discards changes', function () {
    Http::fake(['https://a.test' => Http::response('', 200)]);
    $source = app(SourceRepository::class)->add('My Source', 'https://a.test');

    Native::test(Sources::class)
        ->call('startEdit', $source->key)
        ->call('changeEditUrl', 'https://b.test')
        ->call('cancelEdit')
        ->assertSet('editing', null);

    expect(app(SourceRepository::class)->find($source->key)->config['base_url'])->toBe('https://a.test');
});

it('never offers Edit URL for the built-in the source row', function () {
    Native::test(Sources::class)->call('startEdit', HttpSource::KEY)
        ->assertSet('editing', null);
});
