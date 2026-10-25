<?php

declare(strict_types=1);

namespace App\NativeComponents;

use App\Adapters\Http\RateLimit\Priority;
use App\Covers\CoverStore;
use App\Downloads\DownloadQueue;
use App\Downloads\QueueState;
use App\Models\Chapter;
use App\Models\Manga;
use App\NativeComponents\Concerns\ShowsResumeBar;
use App\NativeComponents\Concerns\ShowsTiles;
use App\NativeLayouts\HasResumeBar;
use App\Repositories\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Facades\Dialog;

/**
 * The Downloads tab (Plan 26 Phase B): a mirror of DownloadQueue::pending() grouped
 * by series with per-row Pause / Resume / Cancel. The comodin lane (read_now_lane)
 * and done rows are deliberately excluded (pending() already filters them). Series
 * title + chapter label follow the configured language chain, just like Library.
 *
 * Phase C (series-level bulk actions and per-chapter page-count progress on the
 * Downloading chip) is a follow-up.
 */
class Downloads extends NativeComponent implements HasResumeBar
{
    use ShowsResumeBar;
    use ShowsTiles;

    public function navTitle(): string
    {
        return 'Downloads';
    }

    public function pause(string $chapterId): void
    {
        app(DownloadQueue::class)->pause($chapterId);
    }

    public function resume(string $chapterId): void
    {
        app(DownloadQueue::class)->resume($chapterId);
    }

    public function cancel(string $chapterId): void
    {
        app(DownloadQueue::class)->cancel($chapterId);
    }

    /**
     * Group-header "Cancel all": confirm before wiping every non-done row for one series.
     * Mirrors Library's askRemoveFromInterestRow pattern (destructive Dialog.Alert + ButtonPressed).
     */
    public function askCancelAllForManga(string $mangaId, string $title): void
    {
        Dialog::alert(
            'Cancel downloads?',
            'All queued, paused, and downloading chapters for "'.$title.'" will be removed from the queue. Chapters already downloaded stay on your device.',
            [
                ['label' => 'Cancel', 'style' => 'cancel'],
                ['label' => 'Cancel all', 'style' => 'destructive'],
            ],
        )->buttonPressed(fn (ButtonPressed $pressed) => $pressed->label === 'Cancel all'
            ? app(DownloadQueue::class)->cancelAllForManga($mangaId)
            : null);
    }

    public function render(): View
    {
        $chain = app(SettingsRepository::class)->chain();
        $queue = app(DownloadQueue::class);
        $comodin = $queue->readNowChapterId();

        // One joined query covers every row the view needs: queue state + the stored_chapters
        // page progress so a Downloading chip can show "Downloading · X%" without an N+1.
        // Mirrors DownloadQueue::pending()'s ordering (position asc) and comodin exclusion.
        $pending = DB::table('downloads')
            ->leftJoin('stored_chapters', 'stored_chapters.chapter_id', '=', 'downloads.chapter_id')
            ->where('downloads.state', '!=', QueueState::Done->value)
            ->when($comodin !== null, fn ($q) => $q->where('downloads.chapter_id', '!=', $comodin))
            ->orderBy('downloads.position')
            ->get([
                'downloads.chapter_id',
                'downloads.manga_id',
                'downloads.state',
                'stored_chapters.pages_done',
                'stored_chapters.page_count',
            ]);

        $mangaIds = $pending->pluck('manga_id')->unique()->all();
        $chapterIds = $pending->pluck('chapter_id')->all();

        /** @var array<string, Manga> $mangas */
        $mangas = Manga::query()->whereIn('id', $mangaIds)->get()->keyBy('id')->all();
        /** @var array<string, Chapter> $chapters */
        $chapters = Chapter::query()->whereIn('id', $chapterIds)->get()->keyBy('id')->all();

        $coverStore = app(CoverStore::class);
        // Any cover that isn't on disk yet is queued like other screens do — the next poll
        // re-render picks the file up.
        $coverStore->queue($mangas, Priority::Bulk);

        $groups = [];

        foreach ($pending as $row) {
            $manga = $mangas[$row->manga_id] ?? null;
            $chapter = $chapters[$row->chapter_id] ?? null;

            if ($manga === null || $chapter === null) {
                continue;
            }

            $mangaId = $manga->id;
            if (! isset($groups[$mangaId])) {
                $groups[$mangaId] = [
                    'mangaId' => $mangaId,
                    'title' => $chain->title($manga->title ?? [], $manga->alt_titles ?? []),
                    'cover' => $coverStore->path($manga),
                    'rows' => [],
                ];
            }

            $state = QueueState::from($row->state);
            $label = self::chapterLabel($chapter);
            $percent = self::percentFor($state, $row->pages_done, $row->page_count);

            $groups[$mangaId]['rows'][] = [
                'chapterId' => $chapter->id,
                'label' => $label,
                'state' => $state->value,
                'chip' => self::chipFor($state, $percent),
                'a11y' => self::a11yFor($label, $state, $percent),
                'actions' => self::actionsFor($state),
            ];
        }

        foreach ($groups as &$group) {
            $n = count($group['rows']);
            $group['count'] = $n === 1 ? '1 chapter' : "{$n} chapters";
        }
        unset($group);

        return view('native.downloads', [
            'groups' => array_values($groups),
        ]);
    }

    /**
     * The caption under a chapter label. Only Downloading surfaces a per-chapter %;
     * every other state keeps its plain label. $percent is null when there is no
     * stored_chapters row yet (first few hundred ms of a download) or page_count=0.
     */
    private static function chipFor(QueueState $state, ?int $percent): string
    {
        return match ($state) {
            QueueState::Queued => 'Queued',
            QueueState::Downloading => $percent === null ? 'Downloading' : "Downloading · {$percent}%",
            QueueState::Paused => 'Paused',
            QueueState::Canceling => 'Canceling',
            QueueState::Done => 'Done',
        };
    }

    /**
     * Compute the download percent for a Downloading row. Returns null for every
     * other state and for Downloading rows whose stored_chapters row isn't filled
     * in yet (page_count=0 or no join hit) — the caller falls back to a plain chip.
     */
    private static function percentFor(QueueState $state, int|string|null $pagesDone, int|string|null $pageCount): ?int
    {
        if ($state !== QueueState::Downloading) {
            return null;
        }

        $done = (int) ($pagesDone ?? 0);
        $total = (int) ($pageCount ?? 0);

        if ($total <= 0) {
            return null;
        }

        return intdiv($done * 100, $total);
    }

    private static function a11yFor(string $label, QueueState $state, ?int $percent): string
    {
        $chip = match ($state) {
            QueueState::Queued => 'Queued',
            QueueState::Downloading => 'Downloading',
            QueueState::Paused => 'Paused',
            QueueState::Canceling => 'Canceling',
            QueueState::Done => 'Done',
        };

        if ($state === QueueState::Downloading && $percent !== null) {
            return "{$label}, {$chip}, {$percent} percent";
        }

        return "{$label}, {$chip}";
    }

    /**
     * @return list<array{action: string, label: string, icon: string, disabled: bool}>
     *                                                                                  Right-aligned icon buttons for the row. Canceling returns a single disabled
     *                                                                                  hourglass marker so the row visibly reflects "work-in-progress, no actions".
     */
    private static function actionsFor(QueueState $state): array
    {
        return match ($state) {
            QueueState::Queued, QueueState::Downloading => [
                ['action' => 'pause', 'label' => 'Pause', 'icon' => 'pause', 'disabled' => false],
                ['action' => 'cancel', 'label' => 'Cancel', 'icon' => 'close', 'disabled' => false],
            ],
            QueueState::Paused => [
                ['action' => 'resume', 'label' => 'Resume', 'icon' => 'play_arrow', 'disabled' => false],
                ['action' => 'cancel', 'label' => 'Cancel', 'icon' => 'close', 'disabled' => false],
            ],
            QueueState::Canceling => [
                ['action' => '', 'label' => 'Canceling', 'icon' => 'hourglass_empty', 'disabled' => true],
            ],
            QueueState::Done => [],
        };
    }
}
