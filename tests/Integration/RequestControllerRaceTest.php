<?php

use App\Adapters\Http\RateLimit\Call;
use App\Adapters\Http\RateLimit\Priority;
use App\Adapters\Http\RateLimit\RequestController;
use App\Adapters\Http\RateLimit\Slot;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/source-race-'.uniqid();
    mkdir($this->dir);
    touch($this->dir.'/db.sqlite');
    config(['database.connections.sqlite.database' => $this->dir.'/db.sqlite']);
    DB::purge('sqlite');
    Artisan::call('migrate', ['--force' => true]);
});

it('gives the last token to exactly one of two processes', function () {
    $call = Call::get('https://api.source.test/manga');
    app(RequestController::class)->acquire($call, Priority::Interactive, 0);
    DB::table('rate_buckets')->where('key', 'global')->update(['tokens' => 1.0, 'refill_per_sec' => 0.0001]);
    DB::disconnect('sqlite');

    $pids = [];
    foreach ([1, 2] as $n) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                DB::purge('sqlite');
                $result = app(RequestController::class)->acquire($call, Priority::Interactive, 0);
                $outcome = $result instanceof Slot ? 'slot' : 'busy';
            } catch (Throwable $e) {
                $outcome = 'error: '.$e->getMessage();
            }
            file_put_contents($this->dir."/child-{$n}", $outcome);
            posix_kill(getmypid(), SIGKILL);
        }
        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $results = [file_get_contents($this->dir.'/child-1'), file_get_contents($this->dir.'/child-2')];
    sort($results);

    expect($results)->toBe(['busy', 'slot']);
})->skip(! function_exists('pcntl_fork'), 'needs the pcntl extension');
