<?php

declare(strict_types=1);

use App\Jobs\EnforceLogCap;
use App\Logging\LogCap;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->storage = sys_get_temp_dir().'/readex-logcap-job-'.bin2hex(random_bytes(4));
    mkdir($this->storage.'/logs', 0755, true);
    app()->useStoragePath($this->storage);
    register_shutdown_function(fn () => File::deleteDirectory($this->storage));
});

it('truncates an oversized log file through the shared LogCap service', function () {
    config(['readex.logging.max_log_bytes' => 100]);
    file_put_contents(storage_path('logs/laravel.log'), str_repeat('x', 5000));

    (new EnforceLogCap)->handle(app(LogCap::class));

    expect(filesize(storage_path('logs/laravel.log')))->toBeLessThan(5000);
});

it('is queueable and unique until it starts, matching EnforceCacheCap', function () {
    expect(new EnforceLogCap)
        ->toBeInstanceOf(ShouldQueue::class)
        ->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->uniqueFor->toBe(3600);
});
