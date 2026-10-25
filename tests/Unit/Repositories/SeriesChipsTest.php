<?php

use App\Repositories\SeriesChips;
use App\Repositories\SeriesDownloadFacts;

function chipsFrom(int $released, int $downloaded, bool $inProgress, bool $opened, bool $allFinished, int $catalogueTotal = -1): SeriesChips
{
    // Defaults the feed total to the stored (released) count, so most fixtures don't need to say it twice.
    return SeriesChips::from(new SeriesDownloadFacts($released, $downloaded, $inProgress, $opened, $allFinished, $catalogueTotal === -1 ? $released : $catalogueTotal));
}

it('shows Downloading while at least one chapter is queued or downloading, independent of everything else', function () {
    expect(chipsFrom(10, 0, true, false, false)->downloading)->toBeTrue()
        ->and(chipsFrom(10, 0, false, false, false)->downloading)->toBeFalse();
});

it('shows Downloaded only when every released chapter is stored, never on a partial download', function () {
    expect(chipsFrom(10, 10, false, false, false)->downloaded)->toBeTrue()
        ->and(chipsFrom(10, 9, false, false, false)->downloaded)->toBeFalse()
        ->and(chipsFrom(0, 0, false, false, false)->downloaded)->toBeFalse();
});

it('shows New only on a fully downloaded series with zero chapters ever opened; any read drops it for the whole series', function () {
    expect(chipsFrom(10, 10, false, false, false)->new)->toBeTrue()
        ->and(chipsFrom(10, 10, false, true, false)->new)->toBeFalse()
        ->and(chipsFrom(10, 9, false, false, false)->new)->toBeFalse(); // partial download is never New either
});

it('shows Finished only against the downloaded subset, independent of Downloaded (can apply on a partial download)', function () {
    expect(chipsFrom(10, 10, false, false, true)->finished)->toBeTrue()
        ->and(chipsFrom(10, 4, false, false, true)->finished)->toBeTrue() // partial but every downloaded chapter read
        ->and(chipsFrom(10, 10, false, false, false)->finished)->toBeFalse()
        ->and(chipsFrom(0, 0, false, false, true)->finished)->toBeFalse(); // nothing downloaded: no Finished either
});

it('Downloaded and Finished are not mutually exclusive: a series can show both at once', function () {
    $chips = chipsFrom(10, 10, false, false, true);

    expect($chips->downloaded)->toBeTrue()->and($chips->finished)->toBeTrue();
});

it('D43: a new chapter upstream never strips Downloaded/Finished — stored rows do not move until a real refresh, only the badge lights up', function () {
    // Was 100% Downloaded + Finished at 10 stored chapters; the feed's own total is now 11.
    $chips = chipsFrom(released: 10, downloaded: 10, inProgress: false, opened: false, allFinished: true, catalogueTotal: 11);

    expect($chips->downloaded)->toBeTrue()
        ->and($chips->finished)->toBeTrue()
        ->and($chips->newChapterAvailable)->toBeTrue();
});

it('has no newChapterAvailable badge once stored count matches the feed total', function () {
    expect(chipsFrom(10, 10, false, false, false, catalogueTotal: 10)->newChapterAvailable)->toBeFalse();
});

it('gives a series with nothing downloaded and nothing in flight no status chip at all', function () {
    $chips = chipsFrom(10, 0, false, false, false);

    expect($chips->downloading)->toBeFalse()
        ->and($chips->downloaded)->toBeFalse()
        ->and($chips->new)->toBeFalse()
        ->and($chips->finished)->toBeFalse();
});
