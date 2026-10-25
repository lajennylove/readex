<?php

namespace App\Sources;

use App\Adapters\Http\RateLimit\Priority;
use App\Repositories\Result;

/**
 * Phase 8 (Plan-08 §2): the generic plumbing every content source exposes — browse/search,
 * chapter-feed, page-fetch — so screens/repositories that read a series' chapters and pages
 * don't need to know which source answered. The HTTP source is the only implementation today
 * (`HttpSource`), wrapping the existing Phase 1–5 repositories/client unchanged (no new
 * behavior, no new network calls); user-added sources and multi-source browsing wiring are
 * explicitly out of this task's scope (Plan-08 Task 1 open question).
 */
interface Source
{
    /** Stable identifier used as the `sources.key` / `collections` namespace later. */
    public function key(): string;

    /** Display name shown in Settings → Sources. */
    public function label(): string;

    /** True for the one source the app ships with; false for any user-added source. */
    public function isBuiltIn(): bool;

    /** Saved search/browse results (SQLite only, same shape `MangaRepository::search()` returns today). */
    public function search(string $query = '', array $tagIds = []): Result;

    /** Fetches page 1 of a search/browse and replaces the saved list. */
    public function refreshSearch(string $query = '', array $tagIds = [], Priority $priority = Priority::Interactive): bool;

    /** Fetches the next page, appended after what's already saved. */
    public function loadMore(string $query = '', array $tagIds = [], Priority $priority = Priority::Interactive): bool;

    /** Saved chapters of one manga + language (same shape `ChapterRepository::get()` returns today). */
    public function chapterFeed(string $mangaId, string $language): Result;

    /** Fetches and replaces the saved chapter feed for one manga + language. */
    public function refreshChapterFeed(string $mangaId, string $language, Priority $priority = Priority::Interactive): void;

    /**
     * Page image URLs in reading order for one chapter + image quality.
     *
     * @return list<string>
     */
    public function pageUrls(string $chapterId, string $quality, Priority $priority = Priority::Interactive): array;
}
