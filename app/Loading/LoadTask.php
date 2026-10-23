<?php

namespace App\Loading;

use App\Refresh\Outcome;
use Native\Mobile\AsyncTask;

/** Setup's DataLoader run on the async lane; progress goes to LoadProgress as it happens. */
class LoadTask extends AsyncTask
{
    /** @return array<string, array> step => Outcome array */
    public function handle(string $run): array
    {
        $outcomes = app(DataLoader::class)->run(
            static fn (string $step, string $state) => LoadProgress::record($run, $step, $state),
        );

        return array_map(fn (Outcome $outcome) => $outcome->toArray(), $outcomes);
    }
}
