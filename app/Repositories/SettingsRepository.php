<?php

namespace App\Repositories;

use App\Language\LanguageChain;
use App\Models\Setting;
use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SettingsRepository
{
    public function language(): string
    {
        return $this->read('language') ?? config('readex.defaults.language');
    }

    public function chain(): LanguageChain
    {
        return new LanguageChain($this->language());
    }

    /** Choice 5: Latest updates is filtered by language, so it goes stale; other lists are re-sorted at read time. */
    public function setLanguage(string $code): void
    {
        $code = strtolower(trim($code));

        if (preg_match('/^[a-z]{2,3}(-[a-z]{2,4})?$/', $code) !== 1) {
            throw new InvalidArgumentException("Not a valid source language code [{$code}].");
        }

        if ($code === $this->language()) {
            return;
        }

        DB::transaction(function () use ($code) {
            $this->write('language', $code);
            DB::table('manga_lists')->where('list_key', 'home.latest')->update(['fetched_at' => null]);
        });
    }

    /** @return list<string> */
    public function contentRatings(): array
    {
        return $this->read('content_ratings') ?? config('readex.defaults.content_ratings');
    }

    /**
     * Choice 5 / spec §8: a different set clears every saved list (Home included); downloads and Library stay.
     *
     * @param  list<string>  $ratings
     */
    public function setContentRatings(array $ratings): void
    {
        $known = config('readex.content_ratings');

        if ($ratings === [] || array_diff($ratings, $known) !== []) {
            throw new InvalidArgumentException('Content ratings must be a non-empty subset of '.implode(', ', $known).'.');
        }

        $ratings = array_values(array_intersect($known, $ratings));

        if ($ratings === $this->contentRatings()) {
            return;
        }

        DB::transaction(function () use ($ratings) {
            $this->write('content_ratings', $ratings);
            DB::table('manga_lists')->delete();
        });
    }

    public function imageQuality(): string
    {
        return $this->read('image_quality') ?? config('readex.defaults.image_quality');
    }

    public function setImageQuality(string $quality): void
    {
        if (! in_array($quality, config('readex.image_qualities'), true)) {
            throw new InvalidArgumentException("Unknown image quality [{$quality}].");
        }

        $this->write('image_quality', $quality);
    }

    public function setupDone(): bool
    {
        return $this->read('setup_done') === true;
    }

    public function markSetupDone(): void
    {
        $this->write('setup_done', true);
    }

    /** Plan-08 Task 5 (D45 §5.6/§6a): the draft Terms/EULA screen is shown once, before Setup. */
    public function eulaAcknowledged(): bool
    {
        return $this->read('eula_acknowledged') === true;
    }

    public function acknowledgeEula(): void
    {
        $this->write('eula_acknowledged', true);
    }

    public function lastDataRefreshAt(): ?CarbonImmutable
    {
        $value = $this->read('last_data_refresh_at');

        return $value === null ? null : CarbonImmutable::parse($value);
    }

    public function markDataRefreshed(): void
    {
        $this->write('last_data_refresh_at', Carbon::now()->toIso8601String());
    }

    public function seasonalListId(): ?string
    {
        return $this->read('seasonal_list_id');
    }

    public function setSeasonalListId(string $id): void
    {
        $this->write('seasonal_list_id', $id);
    }

    /**
     * Opt-in URL for the "This season" row's curated JSON (shape: {id, name, manga_ids}).
     * The row stays hidden on Home until a URL is saved; it comes back empty on Factory reset
     * so a fresh install never fetches a hardcoded list.
     */
    public function seasonalUrl(): ?string
    {
        $value = $this->read('seasonal_url');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function setSeasonalUrl(?string $url): void
    {
        $url = $url === null ? null : trim($url);
        $this->write('seasonal_url', $url === '' ? null : $url);

        if ($url === null || $url === '') {
            // Clearing the URL also clears the learned list id so the fallback path
            // (seasonFromList) doesn't still try to refresh against a now-stale list.
            $this->write('seasonal_list_id', null);
        }

        // Bug #7: the previously saved SEASON list (if any) belonged to a different URL — or
        // to the "no URL set" placeholder clear from DataLoader. Delete it so the next Home
        // read sees empty + stale and immediately refreshes against the newly configured URL.
        DB::table('manga_lists')->where('list_key', 'home.season')->delete();
    }

    /**
     * Opt-in override for the covers CDN. An upstream whose cover images sit on a different
     * host than the API (and that doesn't publish `_meta.json`) needs this filled in by the
     * user — otherwise CoverStore composes URLs against the API host and the fetches 404.
     */
    public function coversUrl(): ?string
    {
        $value = $this->read('covers_url');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function setCoversUrl(?string $url): void
    {
        $url = $url === null ? null : trim($url);
        $this->write('covers_url', $url === '' ? null : $url);

        AppServiceProvider::activateUserSource();
    }

    /**
     * Search keeps its title and tag until the field is emptied or the chip deselected.
     *
     * @return array{query: string, tag_id: ?string}
     */
    public function lastSearch(): array
    {
        $saved = $this->read('last_search') ?? [];

        return ['query' => (string) ($saved['query'] ?? ''), 'tag_id' => $saved['tag_id'] ?? null];
    }

    public function rememberSearch(string $query, ?string $tagId): void
    {
        $this->write('last_search', ['query' => $query, 'tag_id' => $tagId]);
    }

    private function read(string $key): mixed
    {
        return Setting::find($key)?->value;
    }

    private function write(string $key, mixed $value): void
    {
        if ($value === null) {
            Setting::query()->whereKey($key)->delete();

            return;
        }

        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
