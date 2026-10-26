<?php

namespace App\Repositories;

use App\Adapters\Http\Client;
use App\Adapters\Http\Exceptions\ApiError;
use App\Adapters\Http\Exceptions\RequestDeferred;
use App\Adapters\Http\RateLimit\Priority;
use App\Models\AtHome;
use App\Sources\ActiveSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/** The @Home server that serves a chapter's pages (spec §12): looked up per chapter, kept 10 min. */
final class AtHomeRepository
{
    public function __construct(private readonly Client $client) {}

    /** @return Result data: ?AtHome */
    public function get(string $chapterId): Result
    {
        $server = AtHome::query()->find($chapterId);

        return Freshness::result($server, $server?->fetched_at, 'at_home');
    }

    /** @throws RequestDeferred|ApiError|ConnectionException */
    public function refresh(string $chapterId, Priority $priority = Priority::Interactive): AtHome
    {
        $response = $this->client->get($this->path($chapterId), [], $priority);

        return AtHome::query()->updateOrCreate(['chapter_id' => $chapterId], [
            'base_url' => self::resolveBaseUrl((string) $response['baseUrl']),
            'hash' => (string) $response['chapter']['hash'],
            'data_files' => array_values($response['chapter']['data'] ?? []),
            'data_saver_files' => array_values($response['chapter']['dataSaver'] ?? []),
            'fetched_at' => Carbon::now(),
        ]);
    }

    /**
     * A real upstream hands back an absolute `baseUrl` for the @Home node that owns the chapter.
     * A single-endpoint source (the App-Review demo on GitHub Pages, for one) has no separate
     *
     * @Home layer: it serves the images from the same site as the API. In that case the at-home
     * response leaves `baseUrl` empty; fall back to the PARENT of `source.api_url`, so images at
     * `<parent>/data/<hash>/<file>` resolve to a reachable URL. (The demo's tree is
     * `/demo-source/api/...` for API responses and `/demo-source/data/...` for images, siblings
     * under the Pages root — stripping the last `/api` segment gives us the parent they share.)
     */
    private static function resolveBaseUrl(string $responseBaseUrl): string
    {
        $trimmed = trim($responseBaseUrl);

        if ($trimmed !== '' && preg_match('#^https?://#i', $trimmed)) {
            return $trimmed;
        }

        $apiUrl = rtrim(app(ActiveSource::class)->apiUrl(), '/');
        $parent = preg_replace('#/[^/]+$#', '', $apiUrl);

        return $parent === '' ? $apiUrl : $parent;
    }

    /**
     * The saved server while fresh, else a new lookup.
     *
     * @throws RequestDeferred|ApiError|ConnectionException
     */
    public function server(string $chapterId, Priority $priority = Priority::Interactive): AtHome
    {
        $result = $this->get($chapterId);

        return $result->isStale ? $this->refresh($chapterId, $priority) : $result->data;
    }

    /**
     * After an image failure: the next server() asks the source again, also within the client's
     * shared-GET window (which would hand back the same dead server).
     */
    public function forget(string $chapterId): void
    {
        AtHome::query()->whereKey($chapterId)->delete();
        $this->client->forget($this->path($chapterId));
    }

    /**
     * Page image URLs in reading order for a `readex.image_qualities` value.
     *
     * @return list<string>
     */
    public static function pageUrls(AtHome $server, string $quality): array
    {
        [$folder, $files] = match ($quality) {
            'original' => ['data', $server->data_files],
            'data-saver' => ['data-saver', $server->data_saver_files],
            default => throw new InvalidArgumentException("Unknown image quality [{$quality}]."),
        };

        $base = rtrim($server->base_url, '/')."/{$folder}/{$server->hash}/";

        return array_map(fn (string $file) => $base.$file, array_values($files));
    }

    private function path(string $chapterId): string
    {
        return "/at-home/server/{$chapterId}";
    }
}
