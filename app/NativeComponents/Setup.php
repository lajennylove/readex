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
use App\Repositories\SourceRepository;
use App\Repositories\TagRepository;
use App\Storage\StorageBridge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Network;

/**
 * First run (spec §9, reworked Plan-08 Task 3): a content
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
     * The content source URL/API endpoint-- empty by default —
     * never pre-filled with the built-in source's own URL — so the sources table only ever holds what the
     * user actually typed in and loaded from, never a hardcoded preference (D45 §2 proof point).
     */
    public string $sourceUrl = '';

    /** Why the last Continue tap's source check failed (bad URL shape, or it didn't answer). */
    public ?string $sourceError = null;

    /** Optional covers CDN override, used only when the source's images live on a sibling host. */
    public string $coversUrl = '';

    /** Optional curated-list JSON URL that fills the "This season" row on Home. */
    public string $seasonalUrl = '';

    public string $language = 'en';

    public ?string $run = null;

    public int $startedAt = 0;

    /** @var array<string, string> step => LoadProgress state */
    public array $steps = [];

    public function mount(SettingsRepository $settings, StorageBridge $bridge): void
    {
        $this->language = IosLanguageMap::toSourceCode($bridge->preferredLanguage());
        $this->ratings = $settings->contentRatings();

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
     * health-checked first before saving it"); a failed check keeps the reader on this screen with an error
     * instead of silently falling back to anything built in.
     */
    public function continueSetup(): void
    {
        $this->sourceError = null;

        try {
            app(SourceRepository::class)->addFromSetup($this->sourceUrl);
        } catch (InvalidArgumentException $e) {
            $this->sourceError = $e->getMessage();

            return;
        }

        $settings = app(SettingsRepository::class);

        if (Network::status()?->connected === false) {
            $this->phase = self::OFFLINE;

            return;
        }

        $settings->setLanguage($this->language);
        $settings->setContentRatings($this->ratings);

        if ($this->coversUrl !== '') {
            $settings->setCoversUrl($this->coversUrl);
        }

        if ($this->seasonalUrl !== '') {
            $settings->setSeasonalUrl($this->seasonalUrl);
        }

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
        $settings = app(SettingsRepository::class);
        $settings->markSetupDone();
        $settings->markDataRefreshed();
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
            'sourceUrl' => $this->sourceUrl,
            'sourceError' => $this->sourceError,
            'coversUrl' => $this->coversUrl,
            'seasonalUrl' => $this->seasonalUrl,
        ]);
    }
}
