<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Lajennylove\MdxStorage\MdxStorageServiceProvider;
use Native\Mobile\Providers\BrowserServiceProvider;
use Native\Mobile\UI\NativeUIServiceProvider;

class NativeServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * The NativePHP plugins to enable.
     *
     * Only plugins listed here will be compiled into your native builds.
     * This is a security measure to prevent transitive dependencies from
     * automatically registering plugins without your explicit consent.
     *
     * @return array<int, class-string<ServiceProvider>>
     */
    public function plugins(): array
    {
        return [
            MdxStorageServiceProvider::class,
            NativeUIServiceProvider::class,
            BrowserServiceProvider::class,
        ];
    }
}
