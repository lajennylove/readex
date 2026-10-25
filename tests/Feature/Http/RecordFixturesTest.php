<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(1_792_756_800));
    Sleep::fake(syncWithCarbon: true);
    $this->dir = sys_get_temp_dir().'/readex-fixtures-'.uniqid();
});

afterEach(fn () => File::deleteDirectory($this->dir));

it('records the body as sent, kept headers and the request', function () {
    Http::fake(['api.mangadex.org/*' => Http::response(
        '{"result":"ok","data":[{"id":"t1","attributes":{"name":{"en":"Action"},"description":{},"group":"genre"}}]}',
        200,
        ['X-RateLimit-Limit' => '40', 'X-Request-ID' => 'abc', 'Content-Type' => 'application/json'],
    )]);

    $this->artisan('fixtures:record', ['--only' => ['tags'], '--path' => $this->dir])
        ->expectsOutput('tags: ok')
        ->assertSuccessful();

    $raw = File::get("{$this->dir}/tags.json");
    $file = json_decode($raw, true);

    expect($raw)->toContain('"description": {}')
        ->and($file['path'])->toBe('/manga/tag')
        ->and($file['status'])->toBe(200)
        ->and($file['headers'])->toBe(['content-type' => 'application/json', 'x-ratelimit-limit' => '40'])
        ->and($file['recorded_at'])->toBe('2026-10-23T12:00:00+00:00')
        ->and(File::files($this->dir))->toHaveCount(1);
    Http::assertSentCount(1);
});

it('goes through the request controller', function () {
    Http::fake(['api.mangadex.org/*' => Http::response(['result' => 'ok', 'data' => []])]);

    $this->artisan('fixtures:record', ['--only' => ['tags', 'manga-nova'], '--path' => $this->dir])->assertSuccessful();

    expect((int) DB::table('api_usage')->sum('requests'))->toBe(2);
    Http::assertSent(fn (Request $r) => str_starts_with($r->header('User-Agent')[0], 'ReaDex/'));
});

it('keeps the old file and fails when a call fails', function () {
    File::ensureDirectoryExists($this->dir);
    File::put("{$this->dir}/tags.json", 'old');
    Http::fake(['api.mangadex.org/*' => Http::response(['result' => 'error'], 503)]);

    $this->artisan('fixtures:record', ['--only' => ['tags'], '--path' => $this->dir])->assertFailed();

    expect(File::get("{$this->dir}/tags.json"))->toBe('old');
});

it('rejects unknown fixture names without calling the API', function () {
    Http::fake();

    $this->artisan('fixtures:record', ['--only' => ['nope'], '--path' => $this->dir])
        ->expectsOutput('Unknown fixture: nope')
        ->assertFailed();

    Http::assertNothingSent();
});
