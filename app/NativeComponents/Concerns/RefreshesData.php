<?php

namespace App\NativeComponents\Concerns;

use App\Jobs\RefreshRepository;
use App\Refresh\Landed;
use App\Refresh\Outcome;
use App\Refresh\Refresh;
use App\Refresh\RefreshStatus;
use App\Refresh\RefreshTask;
use App\Repositories\Result;
use Illuminate\Support\Carbon;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Exceptions\AsyncTaskException;
use Native\Mobile\Facades\Network;

/**
 * Spec §7 loop for a screen. `load()` reads repositories (SQLite only) and passes each
 * Result to `keepFresh()`; empty or stale data starts a RefreshTask, and its outcome either
 * reloads the screen or sets a §13 status line. Busy/limited refreshes go to the
 * RefreshRepository job, and the screen picks up the job's result by polling Landed.
 */
trait RefreshesData
{
    /** Keys of refreshes running on the async lane. */
    public array $refreshing = [];

    /** Key => ms when its RefreshRepository job was queued. */
    public array $waiting = [];

    /** Key => Outcome array for refreshes that didn't succeed. */
    public array $problems = [];

    /** Key => ms of the last successful refresh, so empty-but-fresh data can't loop. */
    public array $refreshedAt = [];

    /** Key => ms when the poll retries an Offline or Unavailable refresh. */
    public array $retryDueAt = [];

    /** A dropped connection (cellular hand-off, Wi-Fi to 5G) comes back on its own. */
    public const OFFLINE_RETRY_MS = 15_000;

    /** A source outage that outlasts the first retry is asked about once a minute. */
    public const UNAVAILABLE_RETRY_MS = 60_000;

    private bool $forceRefresh = false;

    /** Read every repository the screen shows and call keepFresh() for each Result. */
    abstract protected function load(): void;

    /** Pull to refresh: refresh everything the screen shows, fresh or not. */
    public function pullToRefresh(): void
    {
        $this->problems = [];
        $this->retryDueAt = [];
        $this->forceRefresh = true;

        try {
            $this->load();
        } finally {
            $this->forceRefresh = false;
        }
    }

    /** A screen revealed again: earlier task callbacks were dropped while it was covered. */
    public function onResume(): void
    {
        $this->refreshing = [];
        $this->load();
    }

    #[Poll(3000)]
    public function checkWaitingRefreshes(): void
    {
        foreach ($this->waiting as $key => $queuedAt) {
            $outcome = Landed::since($key, $queuedAt);

            if ($outcome !== null) {
                unset($this->waiting[$key]);
                $this->settle($key, $outcome);
            }
        }

        $this->retryDue();
    }

    /** Offline and Unavailable refreshes are retried while the phone isn't known to be disconnected. */
    private function retryDue(): void
    {
        $now = Carbon::now()->getTimestampMs();
        $due = array_filter($this->retryDueAt, fn (int $at) => $now >= $at);

        if ($due === []) {
            return;
        }

        if (Network::status()?->connected === false) {
            foreach (array_keys($due) as $key) {
                $this->retryDueAt[$key] = $now + self::OFFLINE_RETRY_MS;
            }

            return;
        }

        $this->load();
    }

    private function retryIsDue(string $key): bool
    {
        return isset($this->retryDueAt[$key]) && Carbon::now()->getTimestampMs() >= $this->retryDueAt[$key];
    }

    /** The most important line among this screen's refreshes (§13), or null. */
    public function statusLine(): ?string
    {
        return $this->topProblem()?->statusLine();
    }

    /** The source's outage is what the status line is about: the poll retries it, so there's nothing to offer. */
    protected function retryingOutage(): bool
    {
        return $this->topProblem()?->status === RefreshStatus::Unavailable;
    }

    private function topProblem(): ?Outcome
    {
        $order = [RefreshStatus::Offline, RefreshStatus::Limited, RefreshStatus::Busy, RefreshStatus::Unavailable, RefreshStatus::Failed];
        $outcomes = array_map(Outcome::fromArray(...), $this->problems);

        foreach ($order as $status) {
            foreach ($outcomes as $outcome) {
                if ($outcome->status === $status) {
                    return $outcome;
                }
            }
        }

        return null;
    }

    /**
     * A refresh succeeded, on the async lane or through the RefreshRepository job; `$result` is
     * what the repository method returned (e.g. loadMore's "more available"). Runs before load().
     */
    protected function refreshLanded(string $key, mixed $result): void {}

    /** On the async lane, or waiting for the RefreshRepository job. */
    protected function inFlight(Refresh $refresh): bool
    {
        return isset($this->refreshing[$refresh->key()]) || isset($this->waiting[$refresh->key()]);
    }

    /** `$emptyIsAnswer`: a fetched, fresh, empty list is the source's answer (no recommendations), not missing data. */
    protected function keepFresh(Result $result, Refresh $refresh, bool $emptyIsAnswer = false): void
    {
        $key = $refresh->key();

        if (isset($this->refreshing[$key]) || isset($this->waiting[$key])) {
            return;
        }

        if (! $this->forceRefresh) {
            if (! $result->isStale && (! $result->isEmpty() || $emptyIsAnswer)) {
                return;
            }

            if ($this->refreshedRecently($key) || (isset($this->problems[$key]) && ! $this->retryIsDue($key))) {
                return;
            }
        }

        $this->startRefresh($refresh);
    }

    protected function startRefresh(Refresh $refresh): void
    {
        $key = $refresh->key();
        $this->refreshing[$key] = true;

        RefreshTask::dispatch($refresh)
            ->finished(fn (array $outcome) => $this->refreshFinished($refresh, Outcome::fromArray($outcome)))
            ->failed(fn (AsyncTaskException $e) => $this->refreshFinished($refresh, Outcome::failed()));
    }

    private function refreshFinished(Refresh $refresh, Outcome $outcome): void
    {
        $key = $refresh->key();
        unset($this->refreshing[$key], $this->retryDueAt[$key]);

        if ($outcome->status->retriesLater()) {
            $this->problems[$key] = $outcome->toArray();
            $this->waiting[$key] = Carbon::now()->getTimestampMs();
            RefreshRepository::dispatch($refresh);

            return;
        }

        $this->settle($key, $outcome);
    }

    private function settle(string $key, Outcome $outcome): void
    {
        if ($outcome->status !== RefreshStatus::Ok) {
            $outage = ($this->problems[$key]['status'] ?? null) === RefreshStatus::Unavailable->value;
            $this->problems[$key] = $outcome->toArray();

            $retryIn = match ($outcome->status) {
                RefreshStatus::Offline => self::OFFLINE_RETRY_MS,
                RefreshStatus::Unavailable => $outage ? self::UNAVAILABLE_RETRY_MS : self::OFFLINE_RETRY_MS,
                default => null,
            };

            if ($retryIn !== null) {
                $this->retryDueAt[$key] = Carbon::now()->getTimestampMs() + $retryIn;
            }

            return;
        }

        unset($this->problems[$key]);
        $this->refreshedAt[$key] = Carbon::now()->getTimestampMs();
        $this->refreshLanded($key, $outcome->result);

        $forced = $this->forceRefresh;
        $this->forceRefresh = false;

        try {
            $this->load();
        } finally {
            $this->forceRefresh = $forced;
        }
    }

    private function refreshedRecently(string $key): bool
    {
        return isset($this->refreshedAt[$key])
            && Carbon::now()->getTimestampMs() - $this->refreshedAt[$key] < 60_000;
    }
}
