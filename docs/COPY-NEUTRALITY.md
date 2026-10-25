# Copy neutrality policy

ReaDex is a content-agnostic reader. The app itself must never name, suggest, auto-discover, bundle, or market any specific third-party content site. The only external service named anywhere in the project is **MangaDex**, because it is the one documented, credited API integration shipped as the reference `Source` adapter — the same posture a podcast app has toward a specific podcast directory it credits in its docs.

This policy applies **everywhere a human might read the text**:

- Native Blade views (`resources/views/native/`)
- NativeComponent + NativeLayout PHP classes (`app/NativeComponents/`, `app/NativeLayouts/`) — strings, titles, labels, placeholders, and PHPDoc comments
- Any future localization files added to the project

It applies even to code comments, because content neutrality is a property of the codebase, not a theater performance for a reviewer.

## Enforcement

The policy is enforced by `tests/Feature/CopyNeutralityTest.php`, which runs on every commit and in CI. The test:

1. Scans all three roots above for any case-insensitive match against a list of well-known non-MangaDex manga aggregator / piracy site names.
2. Asserts the Sources screen describes ReaDex as a *"reading client"* that works with *"your own content sources"* — a positive-form check so the neutrality framing cannot silently regress.

Any match fails the suite with a precise *"file X mentions Y"* message.

## When the test fails

- **Rename the offending copy.** Say *"the configured source"*, *"your content source"*, or *"Provider"*, never a brand name.
- **Do not edit the banned-term list to make the failure disappear.** The list is additive — new site names get *added* over time, not removed.
- **Do not add exemptions, allowlists, or "unless in a comment" clauses.** If the term appears in a code comment, rephrase the comment.

The only legitimate reason to modify the banned list is to add newly-emerged aggregator domains. Removals require sign-off.

## Why this is Apple-review-safety relevant

Apple reviewers evaluate the app as users see it. A copy-neutrality guard that only scans Blade views would miss a user-visible string set from a NativeComponent PHP class — the exact kind of near-miss that drives App Store rejections. Scanning both roots closes that gap.

This test is part of the Phase 8 (Multi-Source + Apple Review Safety) acceptance criteria recorded in the Obsidian plan note `Plan-08-Multi-Source-and-Apple-Safety.md §2 / §5.4`.

## Scope boundary

This test guards **copy**. It does not police:

- Which URL a user types into the Setup field (that's a user choice, governed by the EULA).
- Which sources appear in the `sources` table at runtime (user-added, by design).
- Any content fetched from a configured source (fetched directly to the device; ReaDex never touches it on a server).

Those concerns live in the EULA, the first-run flow, and the source adapter architecture — not here.
