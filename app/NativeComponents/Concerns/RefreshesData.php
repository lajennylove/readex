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

    private bool $forceRefresh = false;

    /** Read every repository the screen shows and call keepFresh() for each Result. */
    abstract protected function load(): void;

    /** Pull to refresh: refresh everything the screen shows, fresh or not. */
    public function pullToRefresh(): void
    {
        $this->problems = [];
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
    }

    /** The most important line among this screen's refreshes (§13), or null. */
    public function statusLine(): ?string
    {
        $order = [RefreshStatus::Offline, RefreshStatus::Limited, RefreshStatus::Busy, RefreshStatus::Failed];
        $outcomes = array_map(Outcome::fromArray(...), $this->problems);

        foreach ($order as $status) {
            foreach ($outcomes as $outcome) {
                if ($outcome->status === $status) {
                    return $outcome->statusLine();
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

    /** `$emptyIsAnswer`: a fetched, fresh, empty list is MangaDex's answer (no recommendations), not missing data. */
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

            if ($this->refreshedRecently($key) || isset($this->problems[$key])) {
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
        unset($this->refreshing[$key]);

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
            $this->problems[$key] = $outcome->toArray();

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
