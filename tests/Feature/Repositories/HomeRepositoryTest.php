<?php

use App\Models\Chapter;
use App\Models\Manga;
use App\Models\MangaListEntry;
use App\Models\ReadingProgress;
use App\Models\StoredChapter;
use App\Repositories\ChapterRepository;
use App\Repositories\HomeRepository;
use App\Repositories\LatestUpdate;
use App\Repositories\SettingsRepository;
use App\Sources\ActiveSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\SourceFixtures;

beforeEach(function () {
    Carbon::setTestNow('2026-10-24 12:00:00');
    Sleep::fake(syncWithCarbon: true);
    $this->settings = app(SettingsRepository::class);
    $this->home = app(HomeRepository::class);
});

/** First 8 characters of each id. */
function homeIds(iterable $manga): array
{
    return collect($manga)->map(fn (Manga $m) => substr($m->id, 0, 8))->values()->all();
}

/** Http::fake() stubs stack and the fixture fake throws on anything unrecorded, so start over. */
function fakeHomeAgain(Closure $stub): void
{
    Http::swap(new Factory(app('events')));
    Http::fake($stub);
}

it('loads Popular new titles created in the last 30 days, reordered for the reader', function () {
    $this->settings->setLanguage('es-la');
    SourceFixtures::fake('popular-new');

    $this->home->refreshPopular();
    $result = $this->home->popular();

    expect($result->data)->toHaveCount(20)
        ->and(homeIds($result->data->take(8)))->toBe(['cfef125c', '93704d83', 'af6667a4', 'b61247ba', '99e912e1', '26d2e5bb', '75f8931e', '7d425c5c'])
        ->and($result->isStale)->toBeFalse();

    Carbon::setTestNow('2026-10-24 18:00:00');
    expect($this->home->popular()->isStale)->toBeTrue();
});

it('keeps the API order for an English reader', function () {
    SourceFixtures::fake('popular-new');

    $this->home->refreshPopular();

    expect(homeIds($this->home->popular()->data->take(5)))->toBe(['cfef125c', '7d425c5c', '93704d83', 'af6667a4', 'b61247ba']);
});

it('shows one Latest updates tile per series, newest first, in two calls', function () {
    $this->settings->setLanguage('es-la');
    SourceFixtures::fake('latest-es-la', 'latest-es-la-manga');

    $this->home->refreshLatest();
    $result = $this->home->latest();
    $first = $result->data->first();

    $released = $result->data->map(fn (LatestUpdate $u) => $u->chapter->readable_at->getTimestamp())->all();
    $byRelease = $released;
    rsort($byRelease);

    // Not reordered by language: tiles 5, 6, 9, 10 have no Spanish and stay where they were released.
    expect($released)->toBe($byRelease)
        ->and($result->data)->toHaveCount(20)
        ->and($result->data->map(fn (LatestUpdate $u) => substr($u->manga->id, 0, 8))->take(4)->all())->toBe(['cfff2b2a', '6ad54367', '38179337', 'c9a8ffb7'])
        ->and($result->data->last()->manga->id)->toStartWith('0a62cb38')
        ->and($first->chapter->id)->toBe('91966e76-fbae-42e2-9c3b-f658fc6574ff')
        ->and($first->chapter->only(['language', 'number', 'group_name']))->toBe(['language' => 'es-la', 'number' => '21', 'group_name' => 'Hermit No Fansub'])
        ->and($result->data->every(fn (LatestUpdate $u) => $u->manga->cover_file !== null))->toBeTrue()
        ->and($result->isStale)->toBeFalse();
    Http::assertSentCount(2);

    Carbon::setTestNow('2026-10-24 12:15:00');
    expect($this->home->latest()->isStale)->toBeTrue();
});

it('never turns a Latest chapter into a one-chapter feed', function () {
    $this->settings->setLanguage('es-la');
    SourceFixtures::fake('latest-es-la', 'latest-es-la-manga');
    [$newest, $second] = SourceFixtures::body('latest-es-la')['data'];
    // The second tile's chapter was already loaded by its series' feed an hour ago.
    Chapter::create(['id' => $second['id'], 'manga_id' => 'old', 'language' => 'en', 'number' => '414', 'fetched_at' => Carbon::now()->subHour()]);

    $this->home->refreshLatest();
    $manga = Chapter::find($newest['id'])->manga_id;

    expect(app(ChapterRepository::class)->get($manga, 'es-la')->isEmpty())->toBeTrue()
        ->and(Chapter::find($second['id'])->fetched_at)->not->toBeNull()
        ->and(Chapter::find($second['id'])->number)->toBe('415');
});

it('forgets chapters only the old Latest row used, keeps stored and in-progress ones', function () {
    $this->settings->setLanguage('es-la');
    SourceFixtures::fake('latest-es-la', 'latest-es-la-manga');
    $this->home->refreshLatest();

    $old = MangaListEntry::query()->where('list_key', HomeRepository::LATEST)->orderBy('position')->pluck('chapter_id');
    StoredChapter::create(['chapter_id' => $old[1], 'tier' => 'cache', 'quality' => 'data-saver', 'status' => 'done', 'pages_done' => 1, 'page_count' => 1, 'bytes' => 1]);
    ReadingProgress::create(['chapter_id' => $old[2], 'manga_id' => 'x', 'page' => 1, 'page_count' => 10]);

    // Next refresh: the first three tiles' chapters are gone from the response.
    $chapters = SourceFixtures::body('latest-es-la');
    $chapters['data'] = array_values(array_filter($chapters['data'], fn (array $c) => ! in_array($c['id'], [$old[0], $old[1], $old[2]], true)));
    $manga = SourceFixtures::body('latest-es-la-manga');

    Carbon::setTestNow('2026-10-24 12:20:00');
    fakeHomeAgain(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/chapter') ? Http::response($chapters) : Http::response($manga));
    $this->home->refreshLatest();

    expect(Chapter::find($old[0]))->toBeNull()
        ->and(Chapter::find($old[1]))->not->toBeNull()
        ->and(Chapter::find($old[2]))->not->toBeNull()
        ->and($this->home->latest()->data->pluck('chapter.id'))->not->toContain($old[0]);
});

it('keeps the saved Latest tiles when the cover call fails (Review Focus 4)', function () {
    $this->settings->setLanguage('es-la');
    SourceFixtures::fake('latest-es-la', 'latest-es-la-manga');
    $this->home->refreshLatest();

    Carbon::setTestNow('2026-10-24 12:30:00');
    $chapters = SourceFixtures::body('latest-es-la');
    fakeHomeAgain(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/chapter') ? Http::response($chapters) : throw new ConnectionException('offline'));

    expect(fn () => $this->home->refreshLatest())->toThrow(ConnectionException::class);
    $result = $this->home->latest();

    expect($result->data)->toHaveCount(20)
        ->and($result->isStale)->toBeTrue();
});

it('goes stale when the reading language changes (choice 5)', function () {
    $this->settings->setLanguage('es-la');
    SourceFixtures::fake('latest-es-la', 'latest-es-la-manga');
    $this->home->refreshLatest();

    $this->settings->setLanguage('fr');

    expect($this->home->latest()->isStale)->toBeTrue()
        ->and($this->home->latest()->data)->toHaveCount(20);
});

it('sends the reader\'s chain and stores nothing for an empty answer', function () {
    $this->settings->setLanguage('pt-br');
    fakeHomeAgain(fn () => Http::response(['result' => 'ok', 'data' => [], 'total' => 0]));

    $this->home->refreshLatest();

    Http::assertSent(fn (Request $r) => str_contains(rawurldecode($r->url()), 'translatedLanguage[]=pt-br&translatedLanguage[]=pt&translatedLanguage[]=en'));
    Http::assertSentCount(1);
    expect($this->home->latest()->isEmpty())->toBeTrue();
});

it('populates the Browse row from /manga/_catalog', function () {
    SourceFixtures::fake('browse-catalog');

    $this->home->refreshCatalog();

    expect($this->home->catalog()->data)->toHaveCount(2)
        ->and(homeIds($this->home->catalog()->data))->toBe(['a0000000', 'a0000000']);
});

it('silently hides the Browse row when the source returns 404 on /manga/_catalog', function () {
    Http::fake([
        rtrim(app(ActiveSource::class)->apiUrl(), '/').'/manga/_catalog' => Http::response('not found', 404),
    ]);

    $this->home->refreshCatalog();

    expect($this->home->catalog()->data->isEmpty())->toBeTrue();
});
