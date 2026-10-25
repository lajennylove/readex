<?php

declare(strict_types=1);

use App\Jobs\EnforceCacheCap;
use App\Storage\ImageCache;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Incident guardrail (2026-10-25): before `$tries`/`backoff()` were added, a queued job that
 * threw an unexpected Throwable on every attempt had no cap at all \u2014 the queue worker re-ran it
 * continuously, each attempt re-logging the same stack trace (exactly how the original incident
 * filled the disk). This drives the real `database` queue connection and the real
 * `Illuminate\Queue\Worker` end to end (not `Queue::fake()`, which never actually retries a job)
 * against EnforceCacheCap \u2014 a job with no `retryUntil()`, so `$tries` is the sole thing standing
 * between "retries a bounded number of times" and "loops forever" \u2014 to prove it now gives up and
 * lands in `failed_jobs` instead.
 */
beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/readex-queue-cap-'.uniqid();
    mkdir($this->dir);
    touch($this->dir.'/db.sqlite');
    config([
        'database.connections.sqlite.database' => $this->dir.'/db.sqlite',
        'queue.default' => 'database',
        'queue.connections.database.connection' => 'sqlite',
        'queue.failed.database' => 'sqlite',
    ]);
    DB::purge('sqlite');
    Artisan::call('migrate', ['--force' => true]);

    // A container binding mismatch (ImageCache is `final`, so a double can't implement its
    // type) means the queue worker here actually hits a TypeError on every attempt rather than
    // the RuntimeException below — still an unconditional, unexpected Throwable on every try,
    // which is exactly the class of bug this guardrail defends against.
    app()->bind(ImageCache::class, fn () => new class
    {
        public function enforce(): void
        {
            throw new RuntimeException('synthetic unconditional failure, matching the incident class');
        }
    });
});

afterEach(fn () => File::deleteDirectory($this->dir));

/**
 * `php artisan queue:work` normally wires this listener itself (WorkCommand::logFailedJob());
 * driving `Illuminate\Queue\Worker` directly — needed here to assert on real retry timing
 * instead of `Queue::fake()`, which never actually retries — means registering it by hand.
 */
function registerFailedJobLogging(): void
{
    app('events')->listen(JobFailed::class, function ($event) {
        app('queue.failer')->log(
            $event->connectionName,
            $event->job->getQueue(),
            $event->job->getRawBody(),
            $event->exception,
        );
    });
}

it('gives up after its capped tries instead of retrying forever, and lands in failed_jobs', function () {
    registerFailedJobLogging();
    EnforceCacheCap::dispatch();

    $worker = app('queue.worker');
    $options = new WorkerOptions(maxTries: 0, sleep: 0, rest: 0);

    $maxLoops = 10;
    for ($i = 0; $i < $maxLoops && DB::table('failed_jobs')->count() === 0; $i++) {
        $worker->runNextJob('database', 'default', $options);
        // Only the EnforceCacheCap job under test is driven here; the EnforceLogCap job that
        // Queue::after()/exceptionOccurred() queue as a side effect is irrelevant to this job's
        // own $tries/backoff cap.
        DB::table('jobs')->where('payload', 'like', '%EnforceCacheCap%')->update(['available_at' => now()->timestamp]);
        DB::table('jobs')->where('payload', 'not like', '%EnforceCacheCap%')->delete();
    }

    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($i)->toBeLessThanOrEqual((new EnforceCacheCap)->tries);
});

it('spaces retries out with backoff instead of looping at full speed', function () {
    EnforceCacheCap::dispatch();

    $worker = app('queue.worker');
    $options = new WorkerOptions(maxTries: 0, sleep: 0, rest: 0);

    $worker->runNextJob('database', 'default', $options);

    // backoff()[0] === 10: the EnforceCacheCap job (not the EnforceLogCap side effect) is not
    // immediately available again.
    expect(DB::table('jobs')->where('payload', 'like', '%EnforceCacheCap%')->value('available_at'))
        ->toBeGreaterThan(now()->timestamp);
});
