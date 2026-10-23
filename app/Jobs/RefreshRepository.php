<?php

namespace App\Jobs;

use App\MangaDex\RateLimit\Priority;
use App\Refresh\Landed;
use App\Refresh\Outcome;
use App\Refresh\Refresh;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Spec §5.6: a refresh that got no request slot retries on the queue worker, waiting each
 * time for the controller's retry time. Busy and Limited release; anything else is final.
 */
class RefreshRepository implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const GIVE_UP_AFTER_SECONDS = 3600;

    public int $uniqueFor = self::GIVE_UP_AFTER_SECONDS;

    public function __construct(public readonly Refresh $refresh) {}

    public function uniqueId(): string
    {
        return $this->refresh->key();
    }

    public function retryUntil(): CarbonInterface
    {
        return Carbon::now()->addSeconds(self::GIVE_UP_AFTER_SECONDS);
    }

    public function handle(): void
    {
        $outcome = Outcome::capture(fn () => $this->refresh->run(Priority::Prefetch));

        if ($outcome->status->retriesLater()) {
            $this->release($this->secondsUntil($outcome->retryAt));

            return;
        }

        Landed::put($this->refresh, $outcome);
    }

    public function failed(?Throwable $e): void
    {
        Landed::put($this->refresh, Outcome::failed());
    }

    private function secondsUntil(?float $retryAt): int
    {
        $now = Carbon::now()->getTimestampMs() / 1000;

        return max(1, (int) ceil(($retryAt ?? $now) - $now));
    }
}
