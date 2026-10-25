<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Chapter;
use App\Models\ChapterFeed;
use App\Models\Download;
use App\Models\ReadingProgress;
use App\Models\StoredChapter;
use Illuminate\Support\Collection;

/**
 * Builds the series-level `SeriesDownloadFacts` the status chips (D41/D42/D43) are computed
 * from. Series-level, not per-chapter (D14): everything here is evaluated against the whole
 * series' chapters in the reading chain's language, never a single upload.
 */
final class SeriesStatusRepository
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function factsFor(string $mangaId): SeriesDownloadFacts
    {
        $language = $this->settings->chain()->firstAvailable(
            Chapter::query()->where('manga_id', $mangaId)->whereNotNull('fetched_at')->distinct()->pluck('language')->all(),
        ) ?? Chapter::query()->where('manga_id', $mangaId)->value('language');

        $chapters = $language === null
            ? collect()
            : Chapter::query()->where('manga_id', $mangaId)->where('language', $language)->whereNotNull('fetched_at')->get();

        // One per number (D14), the way the Library already groups uploads.
        $picked = $chapters->groupBy(fn (Chapter $c) => $c->number ?? $c->id)->map(fn (Collection $g) => $g->first());
        $ids = $picked->pluck('id')->values();

        $stored = StoredChapter::query()->whereIn('chapter_id', $ids)
            ->where('status', 'done')->whereIn('tier', ['local', 'icloud'])->pluck('chapter_id');

        $progress = ReadingProgress::query()->whereIn('chapter_id', $ids)->get()->keyBy('chapter_id');
        $downloadedIds = $ids->filter(fn (string $id) => $stored->contains($id));

        $catalogueTotal = $language === null ? 0 : (int) (ChapterFeed::query()
            ->where('manga_id', $mangaId)->where('language', $language)->value('total') ?? $ids->count());

        return new SeriesDownloadFacts(
            releasedCount: $ids->count(),
            downloadedCount: $downloadedIds->count(),
            anyInProgress: Download::query()->where('manga_id', $mangaId)->whereNull('finished_at')->exists(),
            anyChapterOpened: ReadingProgress::query()->where('manga_id', $mangaId)->exists(),
            allDownloadedFinished: $downloadedIds->every(fn (string $id) => $progress->get($id)?->finished_at !== null),
            catalogueTotal: max($catalogueTotal, $ids->count()),
        );
    }
}
