<?php

namespace App\Jobs;

use App\Adapters\Http\RateLimit\Priority;
use App\Loading\DataLoader;
use App\Loading\LoadProgress;
use App\Refresh\RefreshStatus;
use App\Repositories\SettingsRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The "Refresh provider data" refill (spec §7.1) on the queue worker, after Settings has
 * cleared the saved data. Home rows go out `interactive`, tags and covers `bulk`. One pass:
 * a row that comes back busy or failed is refreshed by Home itself.
 */
class RefreshAllData implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Run id of the refill in progress; Settings disables the button while it's set. */
    public const RUNNING_KEY = 'readex.refill.run';

    /** Also the marker's lifetime, so a worker that dies doesn't leave the button disabled. */
    public const TIMEOUT_SECONDS = 600;

    public int $timeout = self::TIMEOUT_SECONDS;

    public int $tries = 1;

    public function __construct(public readonly string $run) {}

    public static function start(string $run): void
    {
        Cache::put(self::RUNNING_KEY, $run, self::TIMEOUT_SECONDS);
        LoadProgress::start($run);
        self::dispatch($run);
    }

    public static function running(): ?string
    {
        return Cache::get(self::RUNNING_KEY);
    }

    public function uniqueId(): string
    {
        return 'refresh-all-data';
    }

    public function handle(DataLoader $loader, SettingsRepository $settings): void
    {
        try {
            $outcomes = $loader->run(
                fn (string $step, string $state) => LoadProgress::record($this->run, $step, $state),
                Priority::Bulk,
            );

            $rows = array_intersect_key($outcomes, array_flip(DataLoader::HOME_ROWS));

            if (array_filter($rows, fn ($outcome) => $outcome->status === RefreshStatus::Ok) !== []) {
                $settings->markDataRefreshed();
            }
        } finally {
            $this->finish();
        }
    }

    public function failed(?Throwable $e): void
    {
        $this->finish();
    }

    private function finish(): void
    {
        if (self::running() === $this->run) {
            Cache::forget(self::RUNNING_KEY);
        }
    }
}
