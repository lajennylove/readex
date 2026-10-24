# ReaDex

ReaDex is a personal reading client you point at your own content sources. It's a native iOS app (via [NativePHP Mobile](https://nativephp.com/docs/mobile/4)) built on Laravel, with a reader, offline downloader, and library — but it ships with **no built-in catalog**. On first launch, you add a content source URL/API yourself; ReaDex never hosts, scrapes, or redistributes any content itself.

## Why it's built this way

ReaDex is designed to be **Apple-review-safe by architecture**, not by disguise:

- **No default unlicensed content pipeline.** Fresh installs start empty. The user must add a content source before anything loads, and that URL is health-checked before it's ever saved.
- **MangaDex as the one built-in, documented source.** [MangaDex's public API](https://api.mangadex.org) is used within its own documented rate limits and terms — it's a real credited integration, not scraping. See `app/MangaDex/` and `app/Sources/MangaDexSource.php`.
- **Generic, user-driven sources.** Any other source is added as a plain URL by the user — ReaDex doesn't curate, suggest, or bundle a list of third-party sites.
- **Neutral marketing/UI copy**, enforced by a standing test (`tests/.../CopyNeutralityTest.php` or equivalent) that fails the suite if any screen ever names a known piracy/aggregator site.
- **Full factory reset.** Settings has a destructive "Delete everything" action (with a scary confirmation dialog) that wipes every table and file on disk and routes the user back through the real first-run flow (EULA → Setup), proving there's no hidden state or preference baked into the app itself.
- **EULA / Terms screen** on first run, making clear the user is responsible for the legality of any source they add. *(Draft text — pending attorney review before public release; see `Plan-08-Multi-Source-and-Apple-Safety.md` in the project's Obsidian notes for the full scope/decision log.)*

## Tech stack

- **PHP 8.3 / Laravel 13** — core application framework
- **[NativePHP Mobile v4](https://nativephp.com/docs/mobile/4)** — native iOS shell, native UI components, native routing (`Route::native(...)`)
- **SQLite** — local on-device storage (library, reading progress, downloads, sources, settings — no external database)
- **Pest** — test suite (unit + feature), run via `composer test` or `./vendor/bin/pest`
- **Laravel Pint** — code style, enforced clean on every merge

## App structure

```
app/
  Console/          Artisan commands
  Covers/            Cover-image handling
  Downloads/         Offline chapter download pipeline
  Http/              HTTP layer (controllers, requests)
  Jobs/              Queued background work
  Language/          Localization
  Loading/           First-run / background data-loading state machine
  MangaDex/          MangaDex API client, rate limiting, response mappers
  Models/            Eloquent models
  NativeComponents/  Native-UI screens (Home, Library, Settings, Setup, Reader, Sources, EULA, ...)
  NativeLayouts/      Native tab/layout shells
  Pages/             Reader page-viewer logic
  Providers/         Laravel service providers
  Refresh/           Manual data-refresh flows
  Repositories/      Data-access layer
  Sources/           Source adapter interface + MangaDex adapter + source health-check
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

Run on a device/simulator via NativePHP tooling per the [NativePHP Mobile docs](https://nativephp.com/docs/mobile/4).

## Content sources

ReaDex requires at least one configured content source to load anything. On first run, Setup asks for a source URL/API — this is validated with a health check (expects a successful HTTP response) before being saved. MangaDex's own public API (`https://api.mangadex.org`) is the one source with a fully working adapter today; it can be added at Setup like any other source. Sources can be added, edited, blocked, or removed later from **Settings → Sources**.

ReaDex is not affiliated with MangaDex. Chapters loaded through the MangaDex source are translated by the scanlation groups credited on each chapter.

## Status

Actively developed. See the project's internal Obsidian decision log (`Plan-05` through `Plan-08`) for phase-by-phase scope, architecture decisions, and the Apple-safety rationale behind the multi-source design — not included in this repository.

## License

Not yet specified. All rights reserved by the author unless/until a license is added.
