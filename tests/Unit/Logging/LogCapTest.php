<?php

declare(strict_types=1);

use App\Logging\LogCap;
use Illuminate\Support\Facades\File;

/**
 * EnforceLogCap backstop (incident 2026-10-25): the overnight incident's log file had no size
 * limit at all. These tests exercise LogCap directly against a real file on disk.
 */
beforeEach(function () {
    $this->storage = sys_get_temp_dir().'/readex-logcap-'.bin2hex(random_bytes(4));
    mkdir($this->storage.'/logs', 0755, true);
    app()->useStoragePath($this->storage);
    register_shutdown_function(fn () => File::deleteDirectory($this->storage));
    config(['readex.logging.max_log_bytes' => 1000]);
});

it('does nothing when there is no log file yet', function () {
    (new LogCap)->enforce();

    expect(is_file(storage_path('logs/laravel.log')))->toBeFalse();
});

it('leaves a log file under the cap alone', function () {
    file_put_contents(storage_path('logs/laravel.log'), str_repeat('x', 500));

    (new LogCap)->enforce();

    expect(filesize(storage_path('logs/laravel.log')))->toBe(500);
});

it('truncates a log file once it passes the configured cap', function () {
    file_put_contents(storage_path('logs/laravel.log'), str_repeat('x', 2000));

    (new LogCap)->enforce();

    $size = filesize(storage_path('logs/laravel.log'));
    expect($size)->toBeLessThan(2000)
        ->and(file_get_contents(storage_path('logs/laravel.log')))->toContain('log truncated');
});

it('never grows past the cap no matter how many times it runs on an actively-written file', function () {
    $path = storage_path('logs/laravel.log');

    for ($i = 0; $i < 50; $i++) {
        file_put_contents($path, str_repeat('同じ例外のスタックトレース'.PHP_EOL, 50), FILE_APPEND);
        (new LogCap)->enforce();

        expect(filesize($path))->toBeLessThanOrEqual(1000 + 200);
    }
});

it('does nothing when the cap is disabled (zero or negative)', function (int $cap) {
    config(['readex.logging.max_log_bytes' => $cap]);
    file_put_contents(storage_path('logs/laravel.log'), str_repeat('x', 5000));

    (new LogCap)->enforce();

    expect(filesize(storage_path('logs/laravel.log')))->toBe(5000);
})->with([0, -1]);
