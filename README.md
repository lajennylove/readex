# ReaDex

ReaDex is a **personal reader client** for iOS. You point it at a content source URL or API that you choose, and it renders what that source returns. Think of it as a specialized, offline-capable browser for serialized reading content — the app is the transport and the reader; the content is yours.

ReaDex ships with **no built-in catalog**, **no bundled content**, and **no curated list of third-party sites**. It does not host, scrape, or redistribute any content. The user is solely responsible for the legality of any source they add, as stated in the in-app EULA.

Technically, ReaDex is a Laravel 13 / PHP 8.3 app running natively on iPhone through [NativePHP Mobile v4](https://nativephp.com/docs/mobile/4), with a native reader, an offline downloader, and a local library.

## Design philosophy

ReaDex is a **content-agnostic reader**, in the same category as a general-purpose IPTV player, an RSS reader, or a web browser: the app provides the plumbing (fetch, cache, render, offline storage), and the user provides the destination.

Concretely, this means:

- **Empty by default.** Fresh installs load nothing. The user must add a content source during first-run setup, and that URL is health-checked before it is saved.
- **No pre-loaded sources.** The app does not ship a list of third-party sites to pick from.
- **Documented reference adapter.** One source adapter is included — a MangaDex API client — strictly as a worked, credited example of how the adapter interface is implemented. [MangaDex's public API](https://api.mangadex.org) is used within its own documented rate limits and terms. ReaDex is not affiliated with MangaDex.
- **Generic, user-driven sources.** Any other source is added by the user as a plain URL. ReaDex neither suggests nor bundles third-party sites.
- **Copy neutrality.** No screen, string, button, help text, or code comment names any specific third-party content site. This is enforced by `tests/Feature/CopyNeutralityTest.php`, which scans Blade views and the NativeComponent / NativeLayout PHP classes on every run. See [`docs/COPY-NEUTRALITY.md`](./docs/COPY-NEUTRALITY.md).
- **Full factory reset.** Settings has a destructive "Delete everything" action (behind a confirmation dialog) that wipes every table and every file on disk and routes the user back through the first-run flow (EULA → Setup). There is no hidden state or preference baked into the app.
- **EULA on first run.** Users must accept terms that make clear they are responsible for the legality of any source they configure. *(Draft text pending attorney review before public release.)*

Separation of concerns, in one sentence: **ReaDex is the reader, not the publisher.**

## Tech stack

- **PHP 8.3 / Laravel 13** — application framework
- **[NativePHP Mobile v4](https://nativephp.com/docs/mobile/4)** — native iOS shell, native UI components, native routing (`Route::native(...)`)
- **SQLite** — local on-device storage (library, reading progress, downloads, sources, settings — no external database)
- **Pest 5** — test suite (unit + feature)
- **Laravel Pint** — code style (PSR-12 base), enforced clean on every merge

## App structure

```
app/
  Console/           Artisan commands
  Covers/            Cover-image handling
  Downloads/         Offline chapter download pipeline
  Http/              HTTP layer (controllers, requests)
  Jobs/              Queued background work
  Language/          Localization
  Loading/           First-run / background data-loading state machine
  MangaDex/          Reference-adapter API client, rate limiting, response mappers
  Models/            Eloquent models
  NativeComponents/  Native-UI screens (Home, Library, Settings, Setup, Reader, Sources, EULA, ...)
  NativeLayouts/     Native tab/layout shells
  Pages/             Reader page-viewer logic
  Providers/         Laravel service providers
  Refresh/           Manual data-refresh flows
  Repositories/      Data-access layer
  Sources/           Source adapter interface + reference adapter + source health-check
  Storage/           On-device file/cache storage

routes/mobile.php    Native route map (/, /eula, /setup, /home, /search, /library,
                     /settings, /settings/sources, reader/detail routes per tab)
```

## Getting started (development)

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
```

Run the test suite:

```bash
composer test
# or
./vendor/bin/pest --parallel
```

Run on a device or simulator via NativePHP tooling per the [NativePHP Mobile docs](https://nativephp.com/docs/mobile/4).

## Content sources

ReaDex requires at least one configured content source before it will load anything. On first run, Setup asks for a source URL or API endpoint; this URL is validated with a health check (expects a successful HTTP response over HTTPS) before being saved. Sources can be added, edited, blocked, or removed later from **Settings → Sources**.

The repository includes one worked reference adapter (`app/Sources/MangaDexSource.php` + `app/MangaDex/`) implementing the `Source` interface against the public MangaDex API. It is intended as both a usable adapter for users who choose to add MangaDex and as the canonical implementation example for anyone writing a new adapter.

## Contributing & standards

All AI assistants and human contributors must follow [`CLAUDE.md`](./CLAUDE.md), which covers PSR-4 / PSR-12 / PHPDoc expectations, Laravel conventions, the testing policy, the branch model (`main` protected; work lands via `feature/<id>-<slug>` or `bugfix/<id>-<slug>` branches, PR + review + green CI), and the Conventional Commits + squash-merge release flow.

The files `.cursorrules`, `.github/copilot-instructions.md`, and `AGENTS.md` are **symbolic links** to `CLAUDE.md` so every assistant reads the same rules. Edit the guidelines in `CLAUDE.md` only.

## Status

Actively developed. Public releases are gated on completion of the multi-source + App-Store-safety phase (EULA, generic source management, enforced copy neutrality, full factory reset, source health check).

## License

Not yet specified. All rights reserved by the author unless and until a license is added.

The reference MangaDex adapter uses the public MangaDex API within its documented terms. ReaDex is not affiliated with MangaDex. Any content loaded through that adapter is translated and credited to the scanlation groups listed on each chapter.
