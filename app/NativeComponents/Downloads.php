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

    /**
     * First-row index for the currently-shown window of pending chapters. Zero means
     * "rows 1..cap". Bumped by `pageDownloads()` in `+cap` steps so a very long queue walks
     * through cap-sized windows instead of trying to ship every row in one native-Element tree —
     * same mechanism the MangaDetail chapter list uses. On a Pixel 9 with ~200 queued chapters
     * the un-paginated tree overflows the Android flat buffer (observed as a `nodes=968 flat=155848`
     * PostTreeUpdate that the WebView accepts but never body-swaps on).
     */
    public int $downloadsOffset = 0;

    public function navTitle(): string
    {
        return 'Downloads';
    }

    /**
     * Walk one `downloads.row_cap`-sized window up or down the pending-chapters list.
     * `$direction` is `+1` for "View next N →" and `-1` for "← View previous N".
     */
    public function pageDownloads(int $direction): void
    {
        if ($direction !== 1 && $direction !== -1) {
            return;
        }

        $cap = (int) config('readex.downloads.row_cap', 400);
        $next = $this->downloadsOffset + ($direction * $cap);
        $this->downloadsOffset = max(0, $next);
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
        //
        // The query reads total count first so the pager rows can label the next/previous window
        // ("View next 400 →"), then slices the current window with limit+offset so the native tree
        // never ships more than $cap rows at once (Android flat-buffer ceiling, same bite point
        // the MangaDetail chapter list works around).
        $cap = (int) config('readex.downloads.row_cap', 400);

        $base = DB::table('downloads')
            ->where('downloads.state', '!=', QueueState::Done->value)
            ->when($comodin !== null, fn ($q) => $q->where('downloads.chapter_id', '!=', $comodin));

        $total = (clone $base)->count();
        $maxOffset = max(0, (int) (floor(max(0, $total - 1) / $cap) * $cap));
        $offset = min(max(0, $this->downloadsOffset), $maxOffset);

        $pending = $base
            ->leftJoin('stored_chapters', 'stored_chapters.chapter_id', '=', 'downloads.chapter_id')
            ->orderBy('downloads.position')
            ->offset($offset)
            ->limit($cap)
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

        $windowEnd = min($offset + $cap, $total);
        $pagerUp = $offset > 0
            ? ['label' => '← View previous '.min($cap, $offset).' downloads', 'direction' => -1]
            : null;
        $pagerDown = $windowEnd < $total
            ? ['label' => 'View next '.min($cap, $total - $windowEnd).' downloads →', 'direction' => 1]
            : null;

        return view('native.downloads', [
            'groups' => array_values($groups),
            'pagerUp' => $pagerUp,
            'pagerDown' => $pagerDown,
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
