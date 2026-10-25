# Copy neutrality policy

ReaDex is a content-agnostic reader. The app itself must never name, suggest, auto-discover, bundle, or market any specific third-party content site. Prior to Plan-08b, MangaDex was named directly in namespaces, class names, config keys, and some user copy as the one documented, credited API integration shipped as the reference `Source` adapter. Plan-08b (T1–T6) completed the generic-rename pass: user-visible strings, config keys, class and namespace names were scrubbed to generic terms. "mangadex" now only survives in a short, documented set of code identifiers that cannot be renamed without losing meaning (see "Exemption policy" below).

This policy applies **everywhere a human might read the text, and now the entire `app/` tree**:

- Native Blade views (`resources/views/native/`)
- NativeComponent + NativeLayout PHP classes (`app/NativeComponents/`, `app/NativeLayouts/`) — strings, titles, labels, placeholders, and PHPDoc comments
- The whole `app/` tree, scanned line-by-line with per-file exemptions for identifier carve-outs
- Any future localization files added to the project

It applies even to code comments, because content neutrality is a property of the codebase, not a theater performance for a reviewer.

## Enforcement

The policy is enforced by `tests/Feature/CopyNeutralityTest.php`, which runs on every commit and in CI. The test:

1. Scans the user-facing roots (Blade views, `app/NativeComponents/`, `app/NativeLayouts/`) for any case-insensitive match against the banned list.
2. Scans the whole `app/` tree line-by-line, with a tightly scoped per-file exemption list for code identifiers that cannot be renamed (persisted DB values, HTTP host literals used by the rate-limit router, API-shape method names).
3. Asserts the Sources screen describes ReaDex as a *"reading client"* that works with *"your own content sources"* — a positive-form check so the neutrality framing cannot silently regress.

Any match fails the suite with a precise *"file X mentions Y"* message.

## Exemption policy

A handful of identifiers inside `app/` legitimately contain the substring `mangadex` and cannot be renamed without breaking real behavior:

- **`HttpSource::KEY = 'mangadex'`** — this is a persisted DB value. Rows in SQLite (`sources.key`, cache keys, saved collections) reference this string; changing it would silently orphan user data.
- **`'mangadex.org'` / `'.mangadex.org'` host literals in `app/Adapters/Http/Client.php`** — the rate-limit router tallies per-host budgets by exact host match. The literal IS the identifier.
- **`isMangaDexHost()` in `app/Adapters/Http/Client.php`** — the method name describes the test it performs on that host literal; renaming it in isolation loses the connection to the literal above.
- **`IosLanguageMap::toMangaDex()` and its call sites in `app/Language/LanguageChain.php` and `app/NativeComponents/Setup.php`** — the method converts iOS BCP-47 language tags to the specific language-code format the reference API expects. The name documents that API-shape contract.

New exemptions require explicit sign-off, not sed-and-forget. The exemption list is a last resort for identifiers whose meaning is their exact spelling. If a brand reference lives in a comment, a string, or a renameable symbol, scrub it — do not exempt it.

## When the test fails

- **Rename the offending copy.** Say *"the configured source"*, *"your content source"*, or *"Provider"*, never a brand name.
- **Do not edit the banned-term list to make the failure disappear.** The list is additive — new site names get *added* over time, not removed.
- **Do not add exemptions to the broader `app/` scan to make a failure disappear.** The exemption list is reserved for identifiers whose spelling is their meaning (see "Exemption policy" above). Comments and renameable symbols get scrubbed, not exempted.

The only legitimate reason to modify the banned list is to add newly-emerged aggregator domains. Removals require sign-off.

## Why this is Apple-review-safety relevant

Apple reviewers evaluate the app as users see it. A copy-neutrality guard that only scans Blade views would miss a user-visible string set from a NativeComponent PHP class — the exact kind of near-miss that drives App Store rejections. Scanning both the user-facing roots and the broader `app/` tree closes that gap, while the explicit exemption list keeps the one unavoidable technical identifier from masking a real regression.

This test is part of the Phase 8 (Multi-Source + Apple Review Safety) acceptance criteria recorded in the Obsidian plan note `Plan-08-Multi-Source-and-Apple-Safety.md §2 / §5.4`.

## Scope boundary

This test guards **copy**. It does not police:

- Which URL a user types into the Setup field (that's a user choice, governed by the EULA).
- Which sources appear in the `sources` table at runtime (user-added, by design).
- Any content fetched from a configured source (fetched directly to the device; ReaDex never touches it on a server).

Those concerns live in the EULA, the first-run flow, and the source adapter architecture — not here.
