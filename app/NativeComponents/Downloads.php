<?php

declare(strict_types=1);

namespace App\NativeComponents;

use App\Downloads\DownloadQueue;
use App\Downloads\QueueState;
use App\Models\Chapter;
use App\Models\Manga;
use App\NativeComponents\Concerns\ShowsResumeBar;
use App\NativeComponents\Concerns\ShowsTiles;
use App\NativeLayouts\HasResumeBar;
use App\Repositories\SettingsRepository;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

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

    public function render(): View
    {
        $chain = app(SettingsRepository::class)->chain();
        $pending = app(DownloadQueue::class)->pending();

        $mangaIds = $pending->pluck('manga_id')->unique()->all();
        $chapterIds = $pending->pluck('chapter_id')->all();

        /** @var array<string, Manga> $mangas */
        $mangas = Manga::query()->whereIn('id', $mangaIds)->get()->keyBy('id')->all();
        /** @var array<string, Chapter> $chapters */
        $chapters = Chapter::query()->whereIn('id', $chapterIds)->get()->keyBy('id')->all();

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
                    'rows' => [],
                ];
            }

            $groups[$mangaId]['rows'][] = [
                'chapterId' => $chapter->id,
                'label' => self::chapterLabel($chapter),
                'state' => $row->state->value,
                'chip' => self::chipFor($row->state),
                // TODO: wire per-chapter page count once PageFetcher exposes it;
                // for now the Downloading chip is just "Downloading" (no "X / Y · Z%").
                'actions' => self::actionsFor($row->state),
            ];
        }

        return view('native.downloads', [
            'groups' => array_values($groups),
        ]);
    }

    private static function chipFor(QueueState $state): string
    {
        return match ($state) {
            QueueState::Queued => 'Queued',
            QueueState::Downloading => 'Downloading',
            QueueState::Paused => 'Paused',
            QueueState::Canceling => 'Canceling',
            QueueState::Done => 'Done',
        };
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
