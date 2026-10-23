<?php

namespace App\Refresh;

use App\MangaDex\RateLimit\Priority;
use Native\Mobile\AsyncTask;

/** A screen's refresh on the async lane. Never throws: the result is an Outcome array. */
class RefreshTask extends AsyncTask
{
    public function handle(Refresh $refresh): array
    {
        return Outcome::capture(static fn () => $refresh->run(Priority::Interactive))->toArray();
    }
}
