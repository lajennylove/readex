<?php

use App\Models\SourceConfig;
use App\Repositories\SourceRepository;
use App\Sources\HttpSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Carbon::setTestNow('2026-10-27 12:00:00');
    $this->repo = app(SourceRepository::class);
});

it('seeds the built-in the source row once, idempotently', function () {
    $this->repo->seedBuiltIn();
    $this->repo->seedBuiltIn();

    expect(SourceConfig::query()->count())->toBe(1);
    $source = SourceConfig::query()->find(HttpSource::KEY);

    expect($source->label)->toBe('Built-in example source')
        ->and($source->kind)->toBe('builtin')
        ->and($source->enabled)->toBeTrue()
        ->and($source->blocked)->toBeFalse();
});

it('lists the built-in source first, seeding it if missing', function () {
    $sources = $this->repo->all();

    expect($sources)->toHaveCount(1)
        ->and($sources->first()->key)->toBe(HttpSource::KEY);
});

it('adds a user source from a generic name + URL once it health-checks ok (Jenny 2026-10-24 17:54 EDT)', function () {
    Http::fake(['https://example.test/api' => Http::response('', 200)]);

    $source = $this->repo->add('My Source', 'https://example.test/api');

    expect($source->kind)->toBe('user')
        ->and($source->label)->toBe('My Source')
        ->and($source->config['base_url'])->toBe('https://example.test/api')
        ->and($source->enabled)->toBeTrue()
        ->and($source->blocked)->toBeFalse()
        ->and($this->repo->all())->toHaveCount(2);
});

it('rejects an empty name or an invalid URL without even checking it', function () {
    Http::fake(fn () => throw new RuntimeException('no network expected for a malformed URL'));

    expect(fn () => $this->repo->add('', 'https://example.test'))->toThrow(InvalidArgumentException::class, 'A source needs a name.')
        ->and(fn () => $this->repo->add('Name', 'not-a-url'))->toThrow(InvalidArgumentException::class, 'Not a valid URL.');
});

it('rejects a URL that fails its health check, nothing is saved', function () {
    Http::fake(['https://example.test/down' => Http::response('', 503)]);

    expect(fn () => $this->repo->add('My Source', 'https://example.test/down'))
        ->toThrow(InvalidArgumentException::class, "Couldn't connect to that source. Check the URL and try again.");
    expect($this->repo->all())->toHaveCount(1); // only the seeded built-in
});

it('rejects a URL that can\'t be reached at all (connection failure)', function () {
    Http::fake(fn () => throw new ConnectionException('offline'));

    expect(fn () => $this->repo->add('My Source', 'https://unreachable.test'))
        ->toThrow(InvalidArgumentException::class, "Couldn't connect to that source. Check the URL and try again.");
});

it('blocks and unblocks a source locally, no reporting backend (D45 §6 item 3)', function () {
    $this->repo->seedBuiltIn();

    $this->repo->setBlocked(HttpSource::KEY, true);
    expect($this->repo->find(HttpSource::KEY)->blocked)->toBeTrue()
        ->and($this->repo->enabled())->toHaveCount(0);

    $this->repo->setBlocked(HttpSource::KEY, false);
    expect($this->repo->find(HttpSource::KEY)->blocked)->toBeFalse()
        ->and($this->repo->enabled())->toHaveCount(1);
});

it('removes a user source outright but never the built-in one', function () {
    Http::fake(['https://example.test' => Http::response('', 200)]);
    $this->repo->seedBuiltIn();
    $user = $this->repo->add('Mine', 'https://example.test');

    $this->repo->remove(HttpSource::KEY);
    expect($this->repo->find(HttpSource::KEY))->not->toBeNull();

    $this->repo->remove($user->key);
    expect($this->repo->find($user->key))->toBeNull();
});

it('enabled() excludes blocked and disabled sources', function () {
    Http::fake(['https://example.test' => Http::response('', 200)]);
    $this->repo->seedBuiltIn();
    $user = $this->repo->add('Mine', 'https://example.test');
    $this->repo->setEnabled($user->key, false);

    expect($this->repo->enabled()->pluck('key')->all())->toBe([HttpSource::KEY]);
});

it('matches a typed URL against the built-in the source API loosely (trailing slash, case)', function () {
    expect($this->repo->matchesBuiltIn('https://api.source.test'))->toBeTrue()
        ->and($this->repo->matchesBuiltIn('https://API.SOURCE.TEST/'))->toBeTrue()
        ->and($this->repo->matchesBuiltIn('https://example.test'))->toBeFalse();
});

it('addFromSetup enables the built-in row instead of duplicating it when the URL is the source\'s own', function () {
    Http::fake(['https://api.source.test' => Http::response('', 200)]);
    $this->repo->seedBuiltIn();
    $this->repo->setEnabled(HttpSource::KEY, false);

    $source = $this->repo->addFromSetup('https://api.source.test');

    expect($source->key)->toBe(HttpSource::KEY)
        ->and($source->enabled)->toBeTrue()
        ->and($this->repo->all())->toHaveCount(1);
});

it('addFromSetup adds a plain user source, labeled after its host, for any other URL', function () {
    Http::fake(['https://example.test/api' => Http::response('', 200)]);

    $source = $this->repo->addFromSetup('https://example.test/api');

    expect($source->kind)->toBe('user')
        ->and($source->label)->toBe('example.test')
        ->and($source->config['base_url'])->toBe('https://example.test/api');
});

it('updateUrl changes a user source after a health check, refuses the built-in row', function () {
    Http::fake(['https://a.test' => Http::response('', 200), 'https://b.test' => Http::response('', 200)]);
    $source = $this->repo->add('Mine', 'https://a.test');

    $updated = $this->repo->updateUrl($source->key, 'https://b.test');
    expect($updated->config['base_url'])->toBe('https://b.test');

    $this->repo->seedBuiltIn();
    expect(fn () => $this->repo->updateUrl(HttpSource::KEY, 'https://b.test'))->toThrow(InvalidArgumentException::class);
});
