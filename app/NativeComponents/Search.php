<?php

namespace App\NativeComponents;

use App\Models\Manga;
use App\Models\Tag;
use App\NativeComponents\Concerns\RefreshesData;
use App\NativeComponents\Concerns\ShowsTiles;
use App\Refresh\Refresh;
use App\Repositories\MangaRepository;
use App\Repositories\Result;
use App\Repositories\SettingsRepository;
use App\Repositories\TagRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View as Views;
use Illuminate\View\View;
use Native\Mobile\Edge\Concerns\HasVirtualListWindow;
use Native\Mobile\Edge\NativeComponent;

/**
 * Spec §5.7, choice 5: a search field pinned at the top of the screen (a nav-bar field in a tab
 * stays tucked away until pulled down, and tabs can't pin it); while it's empty, the tag browser.
 * Results (title, tag, or both) are a 3-column cover grid in a virtual list, one list row per
 * grid row, and the next batch loads when the visible window gets near the end.
 */
class Search extends NativeComponent
{
    use HasVirtualListWindow;
    use RefreshesData;
    use ShowsTiles;

    public const COLUMNS = 3;

    /** Tag group => heading, in screen order. */
    public const TAG_GROUPS = ['genre' => 'Genre', 'theme' => 'Theme', 'format' => 'Format', 'content' => 'Content'];

    /** Grey slot height for grid rows outside the window: w-28 2:3 cover, two title lines, gap. */
    public const ROW_HEIGHT = 220;

    /** Grid rows emitted before the first window change; kept small so native reports scrolling. */
    private const FIRST_WINDOW_ROWS = 15;

    /** The next batch starts once the window's last row is this close to the end. */
    private const LOAD_AHEAD_ROWS = 5;

    /** The field's text as typed; sending back a trimmed copy would overwrite the field while typing. */
    public string $text = '';

    public string $query = '';

    public ?string $tagId = null;

    /** Whether MangaDex has more after the saved results; null until a refresh or loadMore says. */
    public ?bool $more = null;

    public function navTitle(): string
    {
        return 'Search';
    }

    /** Every tab tap makes a new Search screen, so the search lives in the settings, not on the screen. */
    public function mount(SettingsRepository $settings): void
    {
        ['query' => $this->query, 'tag_id' => $tagId] = $settings->lastSearch();
        $this->text = $this->query;
        $this->tagId = $tagId !== null && Tag::query()->whereKey($tagId)->exists() ? $tagId : null;

        $this->setVirtualWindow(0, self::FIRST_WINDOW_ROWS - 1);
        $this->load();
    }

    /** The field's text, debounced on the phone. Empty = back to the tags (or the tag's list). */
    public function searchFor(string $text): void
    {
        $this->text = $text;
        $query = preg_replace('/\s+/u', ' ', trim($text));

        if ($query !== $this->query) {
            $this->query = $query;
            $this->startOver();
        }
    }

    public function openTag(string $tagId): void
    {
        $this->tagId = $tagId;
        $this->startOver();
    }

    public function clearTag(): void
    {
        $this->tagId = null;
        $this->startOver();
    }

    /** The field's clear button. */
    public function clearQuery(): void
    {
        $this->text = '';
        $this->query = '';
        $this->startOver();
    }

    /**
     * virtual_list's window change. Several can arrive while a batch is on its way; the batch's
     * refresh key is in `refreshing` / `waiting` until it lands, so it's asked for once.
     */
    public function windowChanged(int $from, int $to): void
    {
        $this->setVirtualWindow($from, $to);

        if ($this->browsingTags()) {
            return;
        }

        $result = $this->results();
        $this->queueWindowCovers($result);

        $rows = $this->rowCount($result);
        $next = $this->loadMoreRefresh();

        if ($this->more === false || $rows === 0 || $to < $rows - self::LOAD_AHEAD_ROWS || $this->inFlight($next) || $this->inFlight($this->searchRefresh())) {
            return;
        }

        $this->startRefresh($next);
    }

    protected function load(): void
    {
        if ($this->browsingTags()) {
            $this->keepFresh(app(TagRepository::class)->get(), Refresh::of([TagRepository::class, 'refresh']));

            return;
        }

        $result = $this->results();
        $this->keepFresh($result, $this->searchRefresh());
        $this->queueWindowCovers($result);
    }

    protected function refreshLanded(string $key, mixed $result): void
    {
        if (is_bool($result) && in_array($key, [$this->searchRefresh()->key(), $this->loadMoreRefresh()->key()], true)) {
            $this->more = $result;
        }
    }

    public function render(): View
    {
        $line = $this->statusLine();
        $busy = $this->refreshing !== [] || $this->waiting !== [];

        if ($this->browsingTags()) {
            return view('native.search', [
                'text' => $this->text,
                'line' => $line,
                'busy' => $busy,
                'groups' => $this->tagGroups(app(TagRepository::class)->get()->data ?? collect()),
            ]);
        }

        $result = $this->results();
        $chain = app(SettingsRepository::class)->chain();
        $rows = [];

        foreach ($this->windowRows($result) as $index => $mangas) {
            $rows[$index] = array_map(fn (Manga $m) => $this->tile($m, $chain), $mangas);
        }

        // virtual_list item views only get $index.
        Views::share('searchRows', $rows);

        return view('native.search', [
            'text' => $this->text,
            'line' => $line,
            'busy' => $busy,
            'tag' => $this->tagId === null ? null : (Tag::query()->find($this->tagId)?->name['en'] ?? null),
            'query' => $this->query,
            'rowCount' => $this->rowCount($result),
            'from' => $this->virtualWindowFrom,
            'to' => $this->virtualWindowTo,
        ]);
    }

    private function startOver(): void
    {
        app(SettingsRepository::class)->rememberSearch($this->query, $this->tagId);
        $this->more = null;
        $this->problems = [];
        $this->setVirtualWindow(0, self::FIRST_WINDOW_ROWS - 1);
        $this->load();
    }

    private function browsingTags(): bool
    {
        return $this->query === '' && $this->tagId === null;
    }

    /** @return list<string> */
    private function tagIds(): array
    {
        return $this->tagId === null ? [] : [$this->tagId];
    }

    private function results(): Result
    {
        return app(MangaRepository::class)->search($this->query, $this->tagIds());
    }

    private function searchRefresh(): Refresh
    {
        return Refresh::of([MangaRepository::class, 'refreshSearch'], $this->query, $this->tagIds());
    }

    private function loadMoreRefresh(): Refresh
    {
        return Refresh::of([MangaRepository::class, 'loadMore'], $this->query, $this->tagIds());
    }

    private function inFlight(Refresh $refresh): bool
    {
        return isset($this->refreshing[$refresh->key()]) || isset($this->waiting[$refresh->key()]);
    }

    private function rowCount(Result $result): int
    {
        return (int) ceil(($result->data?->count() ?? 0) / self::COLUMNS);
    }

    /** @return array<int, list<Manga>> grid row index => its manga, for the rows in the window */
    private function windowRows(Result $result): array
    {
        $rows = array_chunk(($result->data ?? collect())->values()->all(), self::COLUMNS);

        return array_slice($rows, $this->virtualWindowFrom, $this->virtualWindowTo - $this->virtualWindowFrom + 1, preserve_keys: true);
    }

    private function queueWindowCovers(Result $result): void
    {
        $this->queueCovers(array_merge(...array_values($this->windowRows($result))));
    }

    /** @return array<string, list<array{id: string, name: string}>> heading => chips, TAG_GROUPS order */
    private function tagGroups(Collection $tags): array
    {
        $groups = [];

        foreach ($tags->groupBy('group') as $group => $inGroup) {
            $groups[$group] = $inGroup->map(fn (Tag $t) => ['id' => $t->id, 'name' => $t->name['en'] ?? reset($t->name)])->values()->all();
        }

        $ordered = [];

        foreach ([...array_keys(self::TAG_GROUPS), ...array_diff(array_keys($groups), array_keys(self::TAG_GROUPS))] as $group) {
            if (isset($groups[$group])) {
                $ordered[self::TAG_GROUPS[$group] ?? ucfirst($group)] = $groups[$group];
            }
        }

        return $ordered;
    }
}
