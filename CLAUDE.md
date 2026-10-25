# ReaDex — AI Assistant & Contributor Guidelines

This file is the single source of truth for every AI coding assistant and human contributor working in this repository. The following siblings are **symbolic links** to this file and MUST stay that way:

- `.cursorrules` — Cursor
- `.github/copilot-instructions.md` — GitHub Copilot
- `AGENTS.md` — generic agent spec (OpenAI, Codex, Claude Code `/init`, etc.)

If you edit guidelines, edit **this file only**. Never commit a divergent copy.

---

## 1. What ReaDex is (and is not)

ReaDex is a **personal reader client** built with PHP 8.3 + Laravel 13 + [NativePHP Mobile v4](https://nativephp.com/docs/mobile/4), targeting iOS. It is a **transport-and-render tool**, conceptually equivalent to an IPTV client or a web browser: the user supplies their own content source URL/API, and the app renders what that source returns.

ReaDex ships with **no built-in catalog**, **no bundled content**, and **no curated list of third-party sites**. It never hosts, scrapes, or redistributes content. Responsibility for the legality of any configured source lies entirely with the end user, as stated in the in-app EULA.

Keep this framing in mind when writing code, UI copy, docs, or commit messages. See §6 (Copy Neutrality) — it is enforced by a test.

---

## 2. Tech stack snapshot

| Layer | Choice |
|-------|--------|
| Language | PHP 8.3 |
| Framework | Laravel 13 |
| Native shell | NativePHP Mobile v4 (`Route::native(...)`, Native UI components) |
| Storage | SQLite on-device only |
| Tests | Pest 5 (unit + feature) |
| Style | Laravel Pint (PSR-12 base) |
| JS/build | Vite |
| Package manager | Composer (PHP), npm (JS) |

Autoload roots (see `composer.json`):

- `App\\` → `app/`
- `Database\\Factories\\` → `database/factories/`
- `Database\\Seeders\\` → `database/seeders/`
- `Tests\\` → `tests/`

---

## 3. Code style & standards

### 3.1 PHP

- **PSR-4** autoloading. New classes go under the correct namespace that maps to the correct directory. Do not create ad-hoc namespaces.
- **PSR-12** code style, as enforced by **Laravel Pint**. Run `./vendor/bin/pint` before committing. CI will fail on style violations.
- **`declare(strict_types=1);`** at the top of every new PHP file.
- **Type everything.** Parameter types, return types, and property types are mandatory. Use `void`, `never`, `self`, union types, intersection types, and readonly where appropriate.
- **No `mixed`** unless there is no honest alternative; justify in a PHPDoc line.
- **Constructor property promotion** for simple DTOs and services.
- **Readonly classes/properties** for value objects.
- **Final by default.** Mark classes `final` unless they are explicitly designed for extension.
- **Enums** (backed where the value matters) instead of class constants or magic strings.
- **No static state** outside of true singletons wired through the service container.
- **No facades inside domain code.** Facades are allowed in HTTP controllers, Artisan commands, and service providers only. Everywhere else, inject dependencies.

### 3.2 PHPDoc

Required when any of the following is true:

- The method accepts or returns a collection whose element type the PHP type system cannot express (`array<int, Chapter>`, `Collection<int, Source>`).
- The method throws a documented exception that callers must handle.
- A `@deprecated`, `@internal`, or `@see` annotation genuinely helps a reader.

Not required for self-evident getters/setters with native types. Prefer good names + native types over decorative PHPDoc.

```php
/**
 * Fetch the chapter list for a title.
 *
 * @return Collection<int, Chapter>
 *
 * @throws SourceUnavailableException when the configured source fails its health check.
 */
public function chaptersFor(Title $title): Collection
```

### 3.3 Laravel conventions

- **Thin controllers, fat services/repositories.** Controllers orchestrate; business logic lives in `app/Sources/`, `app/Downloads/`, `app/Repositories/`, etc.
- **Form Requests** for input validation. No `$request->validate([...])` in controllers.
- **Eloquent** for persistence; repositories wrap Eloquent for domain-shaped reads.
- **Queued jobs** (`app/Jobs/`) for anything long-running (downloads, source scans, cover fetches).
- **Config over hard-coded values.** Reach for `config('services.mangadex.base_url')`, never a literal.
- **Routes** live in `routes/mobile.php` (`Route::native(...)`), never ad-hoc in service providers.

### 3.4 Native UI (NativePHP Mobile)

- Screens belong under `app/NativeComponents/`.
- Layout shells under `app/NativeLayouts/`.
- Keep UI components dumb — they render data a controller or view-model prepared. No HTTP calls or DB queries from a Native component.

### 3.5 JavaScript / Vite assets

- Prefer plain, modern ES modules; no new frontend frameworks without discussion.
- Build output must be committed only via `npm run build` + Vite manifest — never hand-edit `public/build/`.

### 3.6 Testing (Pest)

- Every bugfix starts with a failing test that reproduces the bug.
- Every feature ships with feature tests covering the golden path and at least one edge case.
- **Copy neutrality test** (`tests/Feature/CopyNeutralityTest.php` or equivalent) must stay green. See §6.
- Factories for every model; no raw inserts in tests.
- Use `RefreshDatabase` for feature tests touching persistence.
- Run the full suite before pushing: `composer test` or `./vendor/bin/pest --parallel`.

---

## 4. Branching, commits, and CI/CD

### 4.1 Branch model

- **`main` is protected.** No direct commits. No direct pushes. All changes land via pull request + review + green CI.
- Branch names follow this grammar:

  ```
  feature/<ticket-id>-<kebab-slug>
  bugfix/<ticket-id>-<kebab-slug>
  hotfix/<ticket-id>-<kebab-slug>     # production emergency only
  chore/<ticket-id>-<kebab-slug>      # tooling, deps, docs with no runtime impact
  ```

  Examples:
  - `feature/1234-offline-chapter-queue`
  - `bugfix/1287-reader-crashes-on-webp`
  - `chore/1301-bump-pint-to-1.27`

- `<ticket-id>` is the issue/task identifier. If there is no ticket, open one first.
- `<kebab-slug>` is 2–6 lowercase words joined by hyphens; no punctuation.
- One branch = one logical change. Rebase instead of merging `main` back in; keep history linear.

### 4.2 Commit messages

Follow **Conventional Commits**:

```
<type>(<scope>): <imperative subject, ≤ 72 chars>

<body — the "why", wrapped at 100 chars>

Refs: #1234
```

Types: `feat`, `fix`, `refactor`, `test`, `docs`, `chore`, `perf`, `ci`, `build`, `style`.

Scopes track top-level folders where useful: `reader`, `downloads`, `sources`, `setup`, `settings`, `native-ui`, `ci`.

Rules:
- Subject in imperative mood ("add", not "added").
- No noisy "WIP" commits on the final branch — squash locally first.
- Reference the ticket in the body or trailer.
- Never commit secrets, API keys, device tokens, or user data snapshots.

### 4.3 Pull requests

Every PR must:

1. Target `main` from a correctly named branch (see §4.1).
2. Include a PR description with: **Why**, **What changed**, **How it was tested**, **Screenshots / screen recordings** for UI changes, **Risk & rollback** notes.
3. Link the ticket.
4. Pass CI (lint + full test suite).
5. Receive at least one review approval.
6. Be merged via **squash merge** so `main` stays one-commit-per-PR.

### 4.4 CI pipeline (minimum)

CI runs on every push to a non-`main` branch and on every PR targeting `main`:

1. `composer install --no-interaction --prefer-dist`
2. `./vendor/bin/pint --test` — style check, no auto-fix in CI
3. `php artisan config:clear`
4. `./vendor/bin/pest --parallel --ci`
5. (If present) `npm ci && npm run build` to ensure assets still compile

Merging to `main` is blocked unless every step is green.

### 4.5 Release flow

- Version tags follow **SemVer**: `vMAJOR.MINOR.PATCH`.
- Tags are cut from `main` only.
- Release notes are generated from Conventional Commit messages since the previous tag.
- iOS archive/App Store Connect uploads are a manual, signed step performed off-CI by a maintainer with the signing identity.

### 4.6 Hotfix flow

- Branch `hotfix/<ticket>-<slug>` off the tag of the shipped release.
- PR into `main`; after merge, cut a new patch tag.
- Backport by cherry-pick, never by force-push.

---

## 5. Security & privacy

- **No telemetry, no analytics, no crash reporting SDKs** without an explicit, opt-in setting and a user-visible disclosure.
- **All content-source traffic is HTTPS.** The first-run health check rejects non-HTTPS URLs.
- **Secrets** live in `.env` (never committed) and are surfaced via `config/*.php`. Code never reads `env()` outside of config files.
- **User data stays on device.** SQLite + the app's own file storage only. No cloud sync without an explicit, documented opt-in feature.
- **Factory reset** (Settings → Delete everything) must wipe every table and every file under the app's storage directories. Any new persistence must be wired into the reset path; add a test for it.
- Follow OWASP basics: validate at boundaries, parameterize queries (Eloquent does this for you), escape output in Blade.

---

## 6. Copy neutrality (non-negotiable)

ReaDex is content-source agnostic. **No view, string file, screen, button, placeholder, help text, error message, or comment may name a specific third-party content site** (manga aggregator, piracy host, streaming service, etc.).

The reference adapter in `app/MangaDex/` + `app/Sources/MangaDexSource.php` currently names its upstream in namespaces, config keys, class names, and some user-facing refresh / Settings / EULA copy. A generic-rename pass (planned separately in Obsidian under `Plan-08b-Generic-Adapter-Rename.md`) will land those changes end-to-end, after which no brand name is permitted anywhere in the codebase. Until that lands, do not **add** new MangaDex mentions; do not **widen** the exemption.

This is enforced by `tests/Feature/CopyNeutralityTest.php`, which scans Blade views **and** `app/NativeComponents/` + `app/NativeLayouts/` (strings, titles, placeholders, and code comments). Do not weaken, skip, or mark that test as risky. If it fails on your branch, fix the copy — do not edit the test's banned-term list to make the failure go away. See [`docs/COPY-NEUTRALITY.md`](./docs/COPY-NEUTRALITY.md) for the full policy and rationale.

Rule of thumb when writing UI copy: say **"your content source"**, **"the configured source"**, or **"Provider"**, never a brand name.

---

## 7. Working-session etiquette for AI assistants

- Read `README.md`, this file, and the directory of the area you're touching **before** proposing changes.
- Prefer **editing existing files** to creating new ones.
- Match the project's existing patterns before importing new ones.
- Do not add dependencies without discussion. Composer and npm installs are code review material.
- Keep diffs focused. No drive-by reformatting of untouched files.
- When unsure which of two reasonable approaches to take, state the trade-off in one sentence and ask.
- Never bypass pre-commit hooks, PR checks, or branch protection.
- Never generate marketing copy, landing-page text, or any other user-facing text that names a specific third-party content site (see §6).
