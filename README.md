# ReaDex

ReaDex is a **personal reader client** for iOS. You point it at a content source URL or API that you choose, and it renders what the source returns. The app is the transport and the reader; the content is yours.

ReaDex ships with no catalog, no bundled content, and no curated list of sources. Setting one up is the first thing you do on launch.

Technically, it's a Laravel 13 / PHP 8.3 app running natively on iPhone through [NativePHP Mobile v4](https://nativephp.com/docs/mobile/4), with a native reader, an offline downloader, and a local library.

## What it is

A content-agnostic reader, in the same category as a general-purpose media player (think VLC) or an RSS reader: the app provides the plumbing — fetch, cache, render, offline storage — and you provide the destination.

- Fresh installs load nothing. The user adds a content source during first-run setup; the URL is health-checked over HTTPS before it is saved.
- Any source is a plain URL added by the user. ReaDex does not suggest, bundle, or auto-discover sources.
- The in-app EULA on first run makes clear that the user is responsible for the legality of any source they configure, and that fetched content never passes through any server operated by ReaDex.
- Settings has a destructive "Delete everything" action behind a confirmation dialog that wipes every table and every file on disk and routes the user back through the first-run flow. There is no hidden state.

## Tech stack

- **PHP 8.3 / Laravel 13**
- **[NativePHP Mobile v4](https://nativephp.com/docs/mobile/4)** — native iOS shell, native UI components, native routing (`Route::native(...)`)
- **SQLite** — local on-device storage (library, reading progress, downloads, sources, settings — no external database)
- **Pest 5** — test suite (unit + feature)
- **Laravel Pint** — code style (PSR-12 base)

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
  Models/            Eloquent models
  NativeComponents/  Native-UI screens (Home, Library, Settings, Setup, Reader, Sources, EULA, ...)
  NativeLayouts/     Native tab/layout shells
  Pages/             Reader page-viewer logic
  Providers/         Laravel service providers
  Refresh/           Manual data-refresh flows
  Repositories/      Data-access layer
  Sources/           Source adapter interface + source health-check
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

ReaDex requires at least one configured content source before it will load anything. On first run, Setup asks for a source URL or API endpoint; the URL is validated with a health check (expects a successful HTTP response over HTTPS) before being saved. Sources can be added, edited, blocked, or removed later from **Settings → Sources**.

The source-adapter interface lives in `app/Sources/`. A worked reference adapter implementing the interface against a public manga API is included in the repository as an implementation example and a working source anyone can add; any other adapter would follow the same contract.

## Contributing & standards

Contributors (human and AI) follow [`CLAUDE.md`](./CLAUDE.md): PSR-4 / PSR-12 / PHPDoc expectations, Laravel conventions, the testing policy, the branch model (`main` protected; work lands via `feature/<id>-<slug>` or `bugfix/<id>-<slug>` branches, PR + review + green CI), and the Conventional Commits + squash-merge release flow.

The files `.cursorrules`, `.github/copilot-instructions.md`, and `AGENTS.md` are **symbolic links** to `CLAUDE.md` so every assistant reads the same rules. Edit the guidelines in `CLAUDE.md` only.

## Status

Actively developed.

## License

MIT — see [`LICENSE`](./LICENSE).
