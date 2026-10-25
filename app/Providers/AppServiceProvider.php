<?php

namespace App\Providers;

use App\Adapters\Http\RateLimit\LimitsTable;
use App\Jobs\EnforceLogCap;
use App\Sources\SourceRegistry;
use App\Storage\CacheLink;
use App\Storage\LocalStorageBridge;
use App\Storage\NativeStorageBridge;
use App\Storage\StorageBridge;
use Illuminate\Support\Facades\Queue;
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

        // Incident guardrail (2026-10-25): every queue job attempt — whether it finishes, throws,
        // or ultimately fails — checks the log-size backstop afterward. A job stuck re-logging
        // the same exception on every retry (the exact failure mode from the incident) still gets
        // its log file truncated between attempts, instead of relying solely on a cache write
        // (EnforceCacheCap) to ever trigger it.
        Queue::after(fn () => EnforceLogCap::dispatch());
        Queue::exceptionOccurred(fn () => EnforceLogCap::dispatch());
        Queue::failing(fn () => EnforceLogCap::dispatch());
    }
}
