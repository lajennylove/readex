<?php

namespace App\Sources;

use App\Adapters\Http\RateLimit\Priority;
use App\Repositories\AtHomeRepository;
use App\Repositories\ChapterRepository;
use App\Repositories\MangaRepository;
use App\Repositories\Result;
use App\Repositories\SettingsRepository;

/**
 * MangaDex behind the Source interface (Plan-08 Task 1): a thin wrapper over the existing
 * Phase 1–5 repositories/client. No behavior change — same rate limits (D21), same credits,
 * same SQLite tables, same tests. This is the only Source the app ships with (D45 §2: a
 * generic adapter interface, never a built-in scraper for any other site).
 */
final class MangaDexSource implements Source
{
    public const KEY = 'mangadex';

    public function __construct(
        private readonly MangaRepository $manga,
        private readonly ChapterRepository $chapters,
        private readonly AtHomeRepository $atHome,
        private readonly SettingsRepository $settings,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Built-in example source';
    }

    public function isBuiltIn(): bool
    {
        return true;
    }

    public function search(string $query = '', array $tagIds = []): Result
    {
        return $this->manga->search($query, $tagIds);
    }

    public function refreshSearch(string $query = '', array $tagIds = [], Priority $priority = Priority::Interactive): bool
    {
        return $this->manga->refreshSearch($query, $tagIds, $priority);
    }

    public function loadMore(string $query = '', array $tagIds = [], Priority $priority = Priority::Interactive): bool
    {
        return $this->manga->loadMore($query, $tagIds, $priority);
    }

    public function chapterFeed(string $mangaId, string $language): Result
    {
        return $this->chapters->get($mangaId, $language);
    }

    public function refreshChapterFeed(string $mangaId, string $language, Priority $priority = Priority::Interactive): void
    {
        $this->chapters->refresh($mangaId, $language, $priority);
    }

    /** @return list<string> */
    public function pageUrls(string $chapterId, string $quality, Priority $priority = Priority::Interactive): array
    {
        $server = $this->atHome->server($chapterId, $priority);

        return AtHomeRepository::pageUrls($server, $quality);
    }
}
