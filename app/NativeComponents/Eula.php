<?php

namespace App\NativeComponents;

use App\Repositories\SettingsRepository;
use App\Sources\ActiveSource;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * First-run Terms/EULA screen (Plan-08 Task 5, D45 §5.6/§6a). Shown once, before Setup. The
 * clause below is the exact draft from Plan-08-Multi-Source-and-Apple-Safety.md §6a — it is
 * explicitly marked as a draft pending attorney review in the UI itself, not only in the docs,
 * per the scope note's own requirement ("must not be published ... in-app as-is without a
 * lawyer's sign-off" — this screen satisfies that by keeping the notice visible to the user,
 * not by skipping the clause).
 */
class Eula extends NativeComponent
{
    /** The exact draft clause from Plan-08 §6a, unedited. */
    public const CLAUSE = <<<'TEXT'
        User-Added Content Sources

        ReaDex is a reading client. It ships without a built-in catalog of content; before any content loads, you add a content source yourself by providing a URL or API endpoint. ReaDex includes one example source adapter you can enable if you choose to, used only through that source's own publicly documented API and under that source's own published terms.

        ReaDex also lets you add your own additional content sources by providing a URL or API endpoint. Any source you add is chosen, configured, and controlled entirely by you. ReaDex does not host, operate, control, verify, endorse, curate, or recommend any content available through a source you add, and ReaDex's developer has no relationship with, and no knowledge of the legality of, any such source. When you add a source, content is fetched directly from that source to your own device — ReaDex's developer does not see, store, or relay that content on any server it operates.

        You are solely responsible for ensuring that you have the legal right to access, read, and store any content made available through a source you add, including complying with the terms of service, copyright law, and any other applicable law governing that source and its content. If you are unsure whether you have the right to use a particular source, do not add it.

        ReaDex reserves the right to let you block, hide, or remove any source you have added, at your own discretion, at any time.
        TEXT;

    public function navTitle(): string
    {
        return 'Terms';
    }

    public function agree(): void
    {
        $settings = app(SettingsRepository::class);
        $settings->acknowledgeEula();

        // Mirror StartGate: a pre-agnostic build may have marked setup_done without saving a
        // source_url, so re-route through Setup when the source isn't actually configured.
        $this->replace($settings->setupDone() && app(ActiveSource::class)->isConfigured() ? '/home' : '/setup');
    }

    public function render(): View
    {
        return view('native.eula', ['clause' => self::CLAUSE]);
    }
}
