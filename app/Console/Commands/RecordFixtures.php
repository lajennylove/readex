<?php

namespace App\Console\Commands;

use App\MangaDex\Client;
use App\MangaDex\Exceptions\ApiError;
use App\MangaDex\Exceptions\RequestDeferred;
use App\MangaDex\RateLimit\Priority;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;

/**
 * Opt-in: calls the real API. Bodies are saved exactly as sent (an empty object stays `{}`),
 * so tests see the same `[]`/`{}` mix the app gets.
 */
final class RecordFixtures extends Command
{
    protected $signature = 'mangadex:record-fixtures
        {--only=* : Record only these fixture names}
        {--path= : Output folder (default tests/Fixtures/mangadex)}';

    protected $description = 'Record MangaDex responses for the test suite (real API calls)';

    private const KEEP_HEADERS = '/^(x-ratelimit-.+|retry-in|content-type)$/';

    public function handle(Client $client): int
    {
        $fixtures = require base_path('tests/Fixtures/mangadex/fixtures.php');
        $dir = $this->option('path') ?: base_path('tests/Fixtures/mangadex');
        $only = $this->option('only');

        if ($unknown = array_diff($only, array_keys($fixtures))) {
            $this->error('Unknown fixture: '.implode(', ', $unknown));

            return self::FAILURE;
        }

        $captured = null;
        Http::globalResponseMiddleware(function (ResponseInterface $response) use (&$captured) {
            return $captured = $response;
        });

        File::ensureDirectoryExists($dir);
        $failed = 0;

        foreach ($only ? array_intersect_key($fixtures, array_flip($only)) : $fixtures as $name => [$path, $query]) {
            $captured = null;

            try {
                $client->get($path, $query, Priority::Bulk, 30.0);
            } catch (ApiError|RequestDeferred|ConnectionException $e) {
                $this->error("{$name}: {$e->getMessage()}");
                $failed++;

                continue;
            }

            if ($captured === null) {
                $this->error("{$name}: answered from the shared-GET cache, run again in a few seconds");
                $failed++;

                continue;
            }

            File::put("{$dir}/{$name}.json", json_encode([
                'recorded_at' => Carbon::now()->toIso8601String(),
                'method' => 'GET',
                'path' => $path,
                'query' => $query,
                'status' => $captured->getStatusCode(),
                'headers' => $this->headers($captured),
                'body' => json_decode((string) $captured->getBody(), false, flags: JSON_THROW_ON_ERROR),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");

            $this->line("{$name}: ok");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, string> */
    private function headers(ResponseInterface $response): array
    {
        $kept = [];

        foreach ($response->getHeaders() as $name => $values) {
            if (preg_match(self::KEEP_HEADERS, $lower = strtolower($name))) {
                $kept[$lower] = $values[0];
            }
        }

        ksort($kept);

        return $kept;
    }
}
