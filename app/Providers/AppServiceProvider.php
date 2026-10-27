<?php

namespace App\Providers;

use App\Adapters\Http\RateLimit\LimitsTable;
use App\Jobs\EnforceLogCap;
use App\Models\SourceConfig;
use App\Sources\SourceRegistry;
use App\Storage\CacheLink;
use App\Storage\LocalStorageBridge;
use App\Storage\NativeStorageBridge;
use App\Storage\StorageBridge;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;

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
            $this->activateUserSource();
        }

        // Incident guardrail (2026-10-25): every queue job attempt — whether it finishes, throws,
        // or ultimately fails — checks the log-size backstop afterward. A job stuck re-logging
        // the same exception on every retry (the exact failure mode from the incident) still gets
        // its log file truncated between attempts, instead of relying solely on a cache write
        // (EnforceCacheCap) to ever trigger it. Skipped when the job that just ran IS
        // EnforceLogCap itself — otherwise every hook fires again after its own completion,
        // recursing without end (ShouldBeUniqueUntilProcessing only locks until the job *starts*,
        // so it can't prevent this; the lock is long gone by the time the job finishes and these
        // hooks fire).
        Queue::after(fn (JobProcessed $event) => self::isEnforceLogCap($event->job) ? null : EnforceLogCap::dispatch());
        Queue::exceptionOccurred(fn (JobExceptionOccurred $event) => self::isEnforceLogCap($event->job) ? null : EnforceLogCap::dispatch());
        Queue::failing(fn (JobFailed $event) => self::isEnforceLogCap($event->job) ? null : EnforceLogCap::dispatch());
    }

    private static function isEnforceLogCap(mixed $job): bool
    {
        return method_exists($job, 'resolveName') && $job->resolveName() === EnforceLogCap::class;
    }

    /**
     * Swap `source.*` runtime config to the active user source URL (and any metadata it
     * published at `/_meta.json`) so every outbound call uses the user's upstream, not the
     * build-time default. Defaults to deriving covers/report URLs from the API host when
     * no metadata was published.
     */
    private function activateUserSource(): void
    {
        try {
            if (! Schema::hasTable('sources')) {
                return;
            }

            $row = SourceConfig::query()
                ->where('kind', 'user')
                ->where('enabled', true)
                ->first();

            if ($row === null) {
                return;
            }

            $url = rtrim((string) ($row->config['base_url'] ?? ''), '/');

            if ($url === '') {
                return;
            }

            $parent = preg_replace('#/[^/]+$#', '', $url) ?: $url;
            $meta = (array) ($row->config['meta'] ?? []);

            config([
                'source.api_url' => $url,
                'source.static_json' => (bool) ($meta['static_json'] ?? false),
                'source.covers_url' => (string) ($meta['covers_url'] ?? $parent.'/covers'),
                'source.report_url' => (string) ($meta['report_url'] ?? $parent.'/report'),
                'source.status_family' => (string) ($meta['status_family'] ?? config('source.status_family')),
            ]);
        } catch (Throwable) {
            // Boot must survive a missing or unreadable sources row.
        }
    }
}
