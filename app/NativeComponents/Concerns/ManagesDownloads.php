<?php

namespace App\NativeComponents\Concerns;

use App\Downloads\Downloads;
use App\Downloads\DownloadState;
use App\Language\Languages;
use App\Models\Chapter;
use App\Models\Manga;
use App\Models\StoredChapter;
use App\NativeComponents\MangaDetail;
use App\Repositories\SettingsRepository;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Facades\Dialog;

/**
 * Download choices (spec §11.3, D35 10–11, msg 692): Read / Download / Stop download / Remove
 * download on a Detail chapter row, Download all on Detail, Remove download from the Reader bar.
 * NativePHP has no action sheet, so the choices are alert buttons, matched by label. The screen
 * uses OpensChapters (Read).
 */
trait ManagesDownloads
{
    private const CHOOSE_READ = 'Read';

    private const CHOOSE_DOWNLOAD = 'Download';

    private const CHOOSE_STOP = 'Stop download';

    private const CHOOSE_REMOVE = 'Remove download';

    private const REMOVE_MESSAGE = 'It stays readable until the image cache needs the space. Your progress stays.';

    private const CANCEL = ['label' => 'Cancel', 'style' => 'cancel'];

    /** A readable Detail row: Read, plus what its download state allows; for a download the choice is the Remove confirmation. */
    protected function askChapterAction(Chapter $chapter): void
    {
        $remove = ['label' => self::CHOOSE_REMOVE, 'style' => 'destructive'];
        $state = app(Downloads::class)->status($chapter->id)->state;

        $buttons = match ($state) {
            DownloadState::None => [self::CHOOSE_READ, self::CHOOSE_DOWNLOAD],
            DownloadState::Queued, DownloadState::Downloading => [self::CHOOSE_READ, self::CHOOSE_STOP],
            DownloadState::Stopped => [self::CHOOSE_READ, self::CHOOSE_DOWNLOAD, $remove],
            DownloadState::Downloaded => [self::CHOOSE_READ, $remove],
        };
        $message = in_array($state, [DownloadState::Stopped, DownloadState::Downloaded], true) ? self::REMOVE_MESSAGE : '';

        Dialog::alert(
            implode(' · ', array_filter([MangaDetail::chapterLabel($chapter), $chapter->title])),
            $message,
            [self::CANCEL, ...array_map(fn (string|array $b) => is_array($b) ? $b : ['label' => $b, 'style' => 'default'], $buttons)],
        )->buttonPressed(fn (ButtonPressed $pressed) => $this->chapterActionChosen($chapter, $pressed->label));
    }

    private function chapterActionChosen(Chapter $chapter, string $label): void
    {
        $downloads = app(Downloads::class);

        match ($label) {
            self::CHOOSE_READ => $this->showChapter($chapter),
            self::CHOOSE_DOWNLOAD => $downloads->download($chapter->id),
            self::CHOOSE_STOP => $downloads->stop($chapter->id),
            self::CHOOSE_REMOVE => $downloads->remove($chapter->id),
            default => null,
        };
    }

    /** Reader bar: Remove download asks once. */
    protected function askRemoveDownload(string $chapterId): void
    {
        Dialog::alert(self::CHOOSE_REMOVE, self::REMOVE_MESSAGE, [self::CANCEL, ['label' => self::CHOOSE_REMOVE, 'style' => 'destructive']])
            ->buttonPressed(fn (ButtonPressed $pressed) => $pressed->label === self::CHOOSE_REMOVE ? app(Downloads::class)->remove($chapterId) : null);
    }

    /**
     * Download all in `$language`:
     *  - while the series' batch runs → stops it at once;
     *  - fully downloaded already → asks to delete every local copy (progress stays);
     *  - otherwise → confirms the count before enqueueing.
     */
    protected function askDownloadAll(string $mangaId, string $language): void
    {
        $downloads = app(Downloads::class);

        if ($downloads->running($mangaId) !== null) {
            $downloads->stopAll($mangaId);

            return;
        }

        $plan = $downloads->plan($mangaId, $language);

        if ($plan['readable'] === 0) {
            return;
        }

        if ($plan['readable'] === $plan['downloaded']) {
            $this->askDeleteAllDownloads($mangaId, $language);

            return;
        }

        $count = $plan['chapters']->count();

        if ($count === 0) {
            return;
        }

        Dialog::alert(
            'Download all',
            "Download {$count} ".($count === 1 ? 'chapter' : 'chapters').' in '.Languages::name($language).'?',
            [self::CANCEL, ['label' => self::CHOOSE_DOWNLOAD, 'style' => 'default']],
        )->buttonPressed(fn (ButtonPressed $pressed) => $pressed->label === self::CHOOSE_DOWNLOAD ? $downloads->downloadAll($mangaId, $language) : null);
    }

    /**
     * Delete every downloaded chapter of a series' language in one tap. Mirrors the
     * Favorited pattern (Favorite toggle): the Download all CTA shows "Downloaded"
     * when the plan is already full, and tapping it goes here. Progress stays —
     * same contract as the per-chapter Remove download.
     */
    private function askDeleteAllDownloads(string $mangaId, string $language): void
    {
        $manga = Manga::query()->find($mangaId);

        if ($manga === null) {
            return;
        }

        $title = app(SettingsRepository::class)->chain()->title($manga->title ?? [], $manga->alt_titles ?? []);

        Dialog::alert(
            'Delete all downloads?',
            'All downloaded chapters for "'.$title.'" in '.Languages::name($language).' will be removed from this phone. Your reading progress is kept.',
            [self::CANCEL, ['label' => 'Delete', 'style' => 'destructive']],
        )->buttonPressed(fn (ButtonPressed $pressed) => $pressed->label === 'Delete' ? $this->deleteAllDownloads($mangaId, $language) : null);
    }

    /** Demote every locally-stored chapter of the series' language back to the cache tier. */
    private function deleteAllDownloads(string $mangaId, string $language): void
    {
        $downloads = app(Downloads::class);
        $ids = Chapter::query()
            ->where('manga_id', $mangaId)
            ->where('language', $language)
            ->whereNull('external_url')
            ->pluck('id');
        $localIds = StoredChapter::query()
            ->whereIn('chapter_id', $ids)
            ->where('tier', 'local')
            ->pluck('chapter_id');

        foreach ($localIds as $chapterId) {
            $downloads->remove($chapterId);
        }
    }

    /**
     * "Download all", "Downloading 12 / 179 · 7%" while its batch runs, "Downloaded" when every
     * readable chapter of the language is; null when the language has none.
     *
     * @return ?array{label: string, icon: string}
     */
    protected function downloadAllButton(string $mangaId, string $language): ?array
    {
        $downloads = app(Downloads::class);
        $running = $downloads->running($mangaId);

        if ($running !== null) {
            return ['label' => "Downloading {$running['done']} / {$running['total']} · {$running['percent']}%", 'icon' => 'stop.circle'];
        }

        $plan = $downloads->plan($mangaId, $language);

        return match (true) {
            $plan['readable'] === 0 => null,
            $plan['downloaded'] === $plan['readable'] => ['label' => 'Downloaded', 'icon' => 'checkmark.circle'],
            default => ['label' => 'Download all', 'icon' => 'arrow.down.circle'],
        };
    }
}
