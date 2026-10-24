<?php

namespace App\Providers;

use App\MangaDex\RateLimit\LimitsTable;
use App\Sources\SourceRegistry;
use App\Storage\CacheLink;
use App\Storage\LocalStorageBridge;
use App\Storage\NativeStorageBridge;
use App\Storage\StorageBridge;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LimitsTable::class, fn () => LimitsTable::fromConfig());

        $this->app->singleton(StorageBridge::class, fn () => new NativeStorageBridge(
            new LocalStorageBridge(storage_path('app/local-bridge'))
        ));

        $this->app->singleton(SourceRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (! $this->app->runningUnitTests()) {
            CacheLink::ensure($this->app->make(StorageBridge::class));
        }
    }
}
