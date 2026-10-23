<?php

namespace Lajennylove\MdxStorage;

use Illuminate\Support\ServiceProvider;
use Lajennylove\MdxStorage\Commands\CopyAssetsCommand;

class MdxStorageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MdxStorage::class, function () {
            return new MdxStorage;
        });
    }

    public function boot(): void
    {
        // Register plugin hook commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                CopyAssetsCommand::class,
            ]);
        }
    }
}
