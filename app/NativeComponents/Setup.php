<?php

namespace App\NativeComponents;

use App\Language\IosLanguageMap;
use App\Language\Languages;
use App\Loading\DataLoader;
use App\Loading\LoadProgress;
use App\Loading\LoadTask;
use App\NativeComponents\Concerns\ChoosesContentRatings;
use App\Refresh\Refresh;
use App\Refresh\RefreshStatus;
use App\Refresh\RefreshTask;
use App\Repositories\SettingsRepository;
use App\Repositories\TagRepository;
use App\Sources\SourceHealthCheck;
use App\Storage\StorageBridge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Network;

/**
 * First run (spec §9, reworked Plan-08 Task 3 — Jenny 2026-10-24 17:54 EDT): a content
 * source URL (empty by default, no pre-filled URL, health-checked before saving),
 * then language + content rating, then the DataLoader steps with progress. Home opens when
 * every step is done, or after `setup_cutoff_seconds` once a Home row has loaded; empty rows
 * then refresh on Home. `setup_done` is only set when Home opens.
 */
class Setup extends NativeComponent
{
    use ChoosesContentRatings;

    public const SETTINGS = 'settings';

    public const LOADING = 'loading';

    public const OFFLINE = 'offline';

    /** Home is opening; a late task callback must not open it again. */
    public const DONE = 'done';

    public string $phase = self::SETTINGS;

    /**
     * The content source URL/API endpoint. Blank in a normal build — the user types one in.
     * Pre-filled from `config('source.api_url')` when it IS set, which lets a build cut with a
     * `SOURCE_API_URL` env value (TestFlight review builds, local dev) show the field already
     * populated so first-run is one tap. The production build leaves `SOURCE_API_URL` unset, so
     * the field is blank for real users — same behavior as before.
     */
    public string $sourceUrl = '';

    /** Why the last Continue tap's source check failed (bad URL shape, or it didn't answer). */
    public ?string $sourceError = null;

    /**
     * The covers CDN host. Required at Setup — the shipped build pre-fills it from
     * `config('source.covers_url_default')` so the three fields open ready to tap through.
     */
    public string $coversUrl = '';

    public ?string $coversError = null;

    /**
     * The curated-list URL powering the "This season" Home row. Required at Setup — same
     * pre-fill treatment as $coversUrl.
     */
    public string $seasonalUrl = '';

    public ?string $seasonalError = null;

    public string $language = 'en';

    public ?string $run = null;

    public int $startedAt = 0;

    /** @var array<string, string> step => LoadProgress state */
    public array $steps = [];

    public function mount(SettingsRepository $settings, StorageBridge $bridge): void
    {
        $this->language = IosLanguageMap::toMangaDex($bridge->preferredLanguage());
        $this->ratings = $settings->contentRatings();
        $this->sourceUrl = (string) (config('source.api_url_default') ?? '');
        $this->coversUrl = (string) (config('source.covers_url_default') ?? '');
        $this->seasonalUrl = (string) (config('source.seasonal_url_default') ?? '');

        // Tags load while the reader picks; DataLoader skips them if they landed.
        RefreshTask::dispatch(Refresh::of([TagRepository::class, 'refresh']));
    }

    public function changeSourceUrl(string $value): void
    {
        $this->sourceUrl = $value;
    }

    public function changeCoversUrl(string $value): void
    {
        $this->coversUrl = trim($value);
    }

    public function changeSeasonalUrl(string $value): void
    {
        $this->seasonalUrl = trim($value);
    }

    public function chooseLanguage(string $name): void
    {
        $this->language = Languages::codeFor($name) ?? $this->language;
    }

    /**
     * Continue, and Retry after "First launch needs internet". The source URL is required and
     * health-checked first (Jenny: "we need to explain the user than they need to add there
     * their API service they want to use" / "validate if we get any valid response from the api
     * (status) before saving it"); a failed check keeps the reader on this screen with an error
     * instead of silently falling back to anything built in.
     */
    public function continueSetup(): void
    {
        $this->sourceError = null;
        $this->coversError = null;
        $this->seasonalError = null;

        $result = app(SourceHealthCheck::class)->check($this->sourceUrl);

        if (! $result->ok) {
            $this->sourceError = $result->error;

            return;
        }

        if ($this->coversUrl === '' || filter_var($this->coversUrl, FILTER_VALIDATE_URL) === false) {
            $this->coversError = 'Add the covers URL — the host that serves this source\'s cover images.';

            return;
        }

        if ($this->seasonalUrl === '' || filter_var($this->seasonalUrl, FILTER_VALIDATE_URL) === false) {
            $this->seasonalError = 'Add the "This season" JSON URL.';

            return;
        }

        $settings = app(SettingsRepository::class);
        $settings->setSourceUrl($this->sourceUrl);
        $settings->setCoversUrl($this->coversUrl);
        $settings->setSeasonalUrl($this->seasonalUrl);

        if (Network::status()?->connected === false) {
            $this->phase = self::OFFLINE;

            return;
        }

        $settings->setLanguage($this->language);
        $settings->setContentRatings($this->ratings);

        $run = $this->run = (string) Str::uuid();
        LoadProgress::start($run);
        $this->phase = self::LOADING;
        $this->steps = [];
        $this->startedAt = Carbon::now()->getTimestampMs();

        LoadTask::dispatch($run)
            ->timeout(120)
            ->finished(fn (array $outcomes) => $this->loadingFinished(array_map(fn (array $o) => $o['status'], $outcomes)))
            ->failed(fn () => $this->loadingFinished(LoadProgress::read($run)));
    }

    #[Poll(1000)]
    public function checkLoading(): void
    {
        if ($this->phase !== self::LOADING) {
            return;
        }

        $this->steps = LoadProgress::read($this->run);
        $finished = array_filter($this->steps, fn (string $state) => $state !== LoadProgress::RUNNING);

        if (count($finished) === count(DataLoader::STEPS)) {
            $this->loadingFinished($this->steps);

            return;
        }

        $waited = Carbon::now()->getTimestampMs() - $this->startedAt;
        $rowLoaded = array_intersect_key($finished, array_flip(DataLoader::HOME_ROWS));

        if ($waited >= config('readex.setup_cutoff_seconds') * 1000 && in_array(RefreshStatus::Ok->value, $rowLoaded, true)) {
            $this->enterHome();
        }
    }

    /** @param  array<string, string>  $states */
    private function loadingFinished(array $states): void
    {
        if ($this->phase !== self::LOADING) {
            return;
        }

        $this->steps = $states;
        $rows = array_map(fn (string $row) => $states[$row] ?? RefreshStatus::Failed->value, DataLoader::HOME_ROWS);

        if (array_unique($rows) === [RefreshStatus::Offline->value]) {
            $this->phase = self::OFFLINE;

            return;
        }

        $this->enterHome();
    }

    private function enterHome(): void
    {
        $this->phase = self::DONE;
        app(SettingsRepository::class)->markSetupDone();
        $this->replace('/home');
    }

    /** Text after a step's label in the progress list. */
    public static function stepLine(?string $state): string
    {
        return match ($state) {
            null => 'Waiting',
            LoadProgress::RUNNING => 'Loading…',
            RefreshStatus::Ok->value => 'Done',
            RefreshStatus::Offline->value => 'Offline',
            RefreshStatus::Busy->value, RefreshStatus::Limited->value => 'Later',
            default => 'Retries on Home',
        };
    }

    public function render(): View
    {
        return view('native.setup', [
            'languageName' => Languages::name($this->language),
            'languageNames' => array_values(Languages::NAMES),
        ]);
    }
}
