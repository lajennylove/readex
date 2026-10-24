<?php

namespace App\Downloads;

final readonly class DownloadStatus
{
    /** @param  ?int  $percent  stored pages of the page count, floored; null without a page count */
    public function __construct(
        public DownloadState $state,
        public ?int $percent = null,
    ) {}

    /** "Queued", "Downloading 45%", "Download stopped · 45%", "Downloaded"; null when not downloaded. */
    public function caption(): ?string
    {
        $percent = $this->percent === null ? '' : "{$this->percent}%";

        return match ($this->state) {
            DownloadState::None => null,
            DownloadState::Queued => 'Queued',
            DownloadState::Downloading => trim("Downloading {$percent}"),
            DownloadState::Stopped => $percent === '' ? 'Download stopped' : "Download stopped · {$percent}",
            DownloadState::Downloaded => 'Downloaded',
        };
    }

    public function inQueue(): bool
    {
        return in_array($this->state, [DownloadState::Queued, DownloadState::Downloading], true);
    }
}
