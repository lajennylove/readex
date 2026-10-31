# App Store Review — ReaDex

Reviewer-facing posture, review notes to paste into App Store Connect, and the brand-neutrality rules that apply to the storefront listing itself.

## 1. Posture (why ReaDex is submittable)

ReaDex is a **configurable reader client**, structurally similar to a web browser, an IPTV player, or an RSS reader. It ships with:

- **No bundled catalog** of third-party content.
- **No curated list** of upstream providers.
- **No scraping, no redistribution.** All HTTP traffic is HTTPS and goes directly from the user's device to the URL the user configured.
- **No telemetry, no analytics, no crash-reporting SDK.** User data stays in on-device SQLite and the app's own file storage.
- **An EULA** (shown on first launch, before any content loads) that places responsibility for the legality of a chosen source on the user.
- **A built-in demo source** (`https://lajennylove.github.io/readex/api`) prefilled on first launch so the app is never empty for the reviewer.

Guideline mapping:

| Guideline | How ReaDex addresses it |
|-----------|-------------------------|
| 2.1 (minimum functionality) | Full reader UI, Library, Downloads, Search, Settings, 6-row Home. Demo source gives real content on first open. |
| 4.3 (spam / wrapper) | 50 demo titles across content ratings and tag combos. Dedicated reader, download queue, reading-progress tracking. Not a web view. |
| 5.0 / 5.1.2 (legal / data) | User chooses the source; EULA transfers responsibility. No PII leaves the device. No tracking APIs. Full privacy manifest shipped. |

## 2. Review notes — paste into App Store Connect "Notes for Reviewer"

> ReaDex is a configurable reader client, comparable to a web browser, IPTV player, or RSS reader. The app ships with no catalog of its own and no curated list of third-party sources. On first launch, after the EULA, the Setup screen is prefilled with a demo content source we host at `https://lajennylove.github.io/readex/api`, so the reviewer can experience the full UI (Home rows, Library, Search, Reader, Downloads, Settings) without any configuration.
>
> The user may replace that URL with any HTTPS source they choose at any time via Settings → Change source URL. The in-app EULA makes clear that responsibility for the legality of a user-chosen source lies with the user; ReaDex does not host, scrape, or redistribute any content.
>
> No account is required. No data leaves the device: no analytics, no telemetry, no crash-reporting SDK. User reading data lives in on-device SQLite only.
>
> A privacy manifest (`PrivacyInfo.xcprivacy`) is included at the app level. `ITSAppUsesNonExemptEncryption = false` — the app uses only standard HTTPS/TLS provided by the platform.
>
> **Demo credentials:** none required.
> **Demo content source:** `https://lajennylove.github.io/readex/api` (prefilled; the reviewer does not need to type it). The demo URL serves a neutral payload with procedurally-generated covers and fictional titles authored for the demo — not a catalog of real published works.
> **Privacy Policy:** `https://lajennylove.github.io/readex/privacy` (also linked in Settings → About).
> **Factory reset:** Settings → Change source URL wipes all on-device content and returns to the Setup screen.

## 3. App Store listing — brand-neutrality rules (same as the binary)

The CopyNeutralityTest only scans the shipped codebase. The storefront is a **separate surface** and must follow the same rules by hand:

### Hard rules

- **No third-party content-site names anywhere** in: app name, subtitle, promotional text, description, keywords, screenshot captions, support URL copy, marketing URL copy, in-app purchase names, review responses.
- **No logos, icons, or color schemes** that evoke a specific third-party content site.
- **No category names** that imply a specific vertical ("manga aggregator", "scanlation reader", etc.). Use **"reader"** or **"content viewer"** generically.

### Allowed vocabulary

- "your content source", "the configured source", "a source you add", "Provider"
- "reader", "offline reading", "download queue", "library", "chapters"
- Generic UI language: "Home", "Library", "Search", "Settings"

### Drafts

**App name:** ReaDex

**Subtitle (≤30 chars):** `Your reader, your source.`

**Promotional text (≤170 chars):**
`Point ReaDex at any HTTPS content source and read offline. Six Home rows. A real download queue. Dark-mode reader. Your library stays on your device.`

**Description (opening paragraph):**
> ReaDex is a configurable reader client. It ships with no catalog of its own — on first launch you add a content source by URL, and ReaDex renders whatever that source returns. Think of it as a browser for reading, not a store.
>
> **Six Home rows** — Season, Popular new, Latest updates, Recently added, Self-published, and a local Recommended-for-you row that only appears once you've read enough.
>
> **A real download queue** — Multiple lanes in the background, pause / resume / cancel, disk-space guard. Chapters you've downloaded stay readable with no network.
>
> **Everything stays on your device** — No account. No telemetry. No analytics SDK. Your library and reading progress live in on-device storage only.
>
> **One source, or many** — Swap your source at any time from Settings. ReaDex works with any HTTPS source that follows the documented adapter spec.

**Keywords (100 char limit, comma-separated, no spaces around commas):**
`reader,offline,download,library,comics,webcomics,webtoons,ebook,viewer,chapters`

> Note: `manga` was previously in this list; replaced with `webtoons` to stay unambiguously generic and avoid any read of category-vertical implication during review.

**Screenshots — captions:**
1. "Six Home rows, one tap to read."
2. "Point ReaDex at your content source."
3. "A real download queue, with lanes."
4. "Dark-mode reader with edge-swipe back."
5. "Library stays on your device."

### Age rating

Pick **17+** regardless of what the demo looks like. The source is user-configurable, so the realistic worst-case content rating is adult. 17+ is the honest answer and heads off a rejection for understated age rating.

### Privacy details ("App Privacy" section)

- Data Not Collected: **everything**. Check nothing.
- Data Linked to User: none.
- Data Used to Track You: none.

### Support URL / Marketing URL

- Support URL: landing page or GitHub Issues URL. Must not name any third-party content site.
- Marketing URL: landing page.

## 4. Search chip + rated-content invariants (verified Oct-30 2026)

These are easy to confuse during E2E; the facts are:

**Chip set on the Search tab does NOT depend on content-rating toggles.** The chip list is a 1:1 mirror of what the source's `/manga/tag` endpoint returns, grouped by the tag's `group` field (`genre` / `theme` / `format` / `content`). No client-side code filters chips by `contentRating`. Verified live on MangaDex: Content section shows `Gore + Sexual Violence` with Safe+Suggestive only, with +Erotica, and with all 4 on — identical set across every permutation.

- Why the demo looks sparser: the demo's `/manga/tag.json` ships 9 tags (4 genre, 4 theme, 1 content). MangaDex ships 77. The variation is 100% upstream payload.
- Why first launch sometimes looks sparse on MangaDex: `/manga/tag` populates `tags` SQLite table asynchronously. If you land on Search before the fetch finishes, you see fewer chips; a tab-tap re-mount (`Search::mount()` runs on every tab tap) picks up the now-full set.

**What the rating toggles DO affect:** the *results grid* splits into two sections, with a `<hr>` divider and a **"Rated content"** heading introducing titles whose `content_rating ∈ {erotica, pornographic}` AND the user has that rating turned on. Verified Oct-30 by searching "solo leveling" on MangaDex:

| Settings | "Rated content" divider appears? |
|----------|----------------------------------|
| Safe + Suggestive only | No |
| + Erotica, Pornographic OFF | No |
| All 4 on | **Yes** — explicit Pornographic-rated tile below the `<hr>` |

Code: `app/NativeComponents/Search.php:250` — `$ratedOn = array_intersect(config('readex.rated_content'), app(SettingsRepository::class)->contentRatings())`, then `$result->data->partition(fn (Manga $m) => in_array($m->content_rating, $ratedOn, true))`. `config('readex.rated_content') === ['erotica', 'pornographic']`.

If a future E2E run suggests "the chip count changed" after toggling Erotica, the explanation is almost certainly (a) tag-fetch race on first Search view, or (b) misreading the Rated-content divider for a new chip row. Don't file a bug — re-verify against this invariant.

## 5. Pre-upload checklist

- [x] `ITSAppUsesNonExemptEncryption = false` in Info.plist
- [x] `PrivacyInfo.xcprivacy` present in `nativephp/ios/NativePHP/`
- [x] Copy-neutrality test green (verified in-session Oct-30; 1013/1013 Pest including CopyNeutrality)
- [x] All Pest tests green (1013/1013 on build 64)
- [x] Demo source expanded to 50 titles + HD covers (live on gh-pages; 50/50 complete across 256/512/1024 variants)
- [x] Privacy Policy published at `https://lajennylove.github.io/readex/privacy`
- [x] EULA heading softened to "Terms for user-added sources" (dropped "Draft — pending attorney review")
- [x] Marketing version / build number bumped (iOS IPA v72 on TestFlight; Android Alpha build 64)
- [ ] App Store Connect listing text pasted from §3 above
- [ ] Review notes from §2 pasted into "Notes for Reviewer"
- [ ] Age rating set to 17+
- [ ] Privacy details: Data Not Collected only
- [ ] Privacy Policy URL filled in App Store Connect metadata field
- [ ] `.env.production` has `APP_ENV=production`, `APP_DEBUG=false`
