<?php

namespace App\Downloads;

final readonly class DownloadStatus
{
    /** @param  ?int  $percent  stored pages of the page count, floored; Downloading and Stopped only */
    public function __construct(
        public DownloadState $state,
        public ?int $percent = null,
    ) {}

    /** "Queued", "Downloading 45%", "Download stopped · 45%", "Downloaded"; null when not downloaded. */
    public function caption(): ?string
    {
        return match ($this->state) {
            DownloadState::None => null,
            DownloadState::Queued => 'Queued',
            DownloadState::Downloading => "Downloading {$this->percent}%",
            DownloadState::Stopped => "Download stopped · {$this->percent}%",
            DownloadState::Downloaded => 'Downloaded',
        };
    }
}
