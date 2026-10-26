# ReaDex demo content source

A static GitHub Pages site that serves a tiny, invented catalog shaped like the
HTTP contract ReaDex's built-in adapter expects. Its only purpose is to give
Apple App Review (and any first-time user) a working URL to paste into the
first-run Setup screen so the app can be exercised end-to-end.

Nothing here is scraped, licensed, or derived from any third-party service.

## Contents

Three invented titles, each with two chapters of four placeholder pages:

- `The Cartographer's Journal`
- `Signal Across the Water`
- `Seasons on the Northern Shelf`

Covers and pages are plain geometric SVGs labelled with their chapter and
page number. Enough to see the catalog, open a title, download a chapter,
and read it.

## Deploy

GitHub Pages, served from this `demo-source/` directory on
`feature/0034-demo-source-review-pages` (or whatever branch you choose in
Settings → Pages → Source). `.nojekyll` is present so Pages serves the raw
file tree without running Jekyll.

Expected URL once Pages is enabled on this branch:

    https://<owner>.github.io/<repo>/demo-source/

## Use in ReaDex

- Open Setup (first launch).
- Paste `https://<owner>.github.io/<repo>/demo-source/api` as the source URL.
- Give the source any label.
- Browse, open, download, read.

See `../docs/TESTFLIGHT.md` for the exact text to paste in App Store Connect
→ App Review Information.

## Rebuild

    python3 build.py

Idempotent. No external dependencies. Generates:

- Catalog + per-title JSON responses under `api/`.
- Cover SVGs under `covers/<title-id>/`.
- Page SVGs under `data/<chapter-id>/` and `data-saver/<chapter-id>/`.

The `baseUrl` placeholder in each at-home server JSON is literally the string
`{BASE}` — a deploy step (or a reviewer config override) must replace it with
the deployed Pages URL. See the "Open gaps" section below.

## Open gaps

- The ReaDex app's base URL today comes from `config/source.php` (`api_url`,
  `covers_url`), not from each saved source's `base_url`. A user-added source
  in Settings → Sources is stored but not yet routed through for API calls.
  Shipping this demo through App Review requires either (a) finishing the
  multi-source routing so a saved source's `base_url` is actually what the
  Client uses, or (b) a review-only build whose `source.api_url` and
  `source.covers_url` are set to this site. Flagged in the fork report.

- At-home JSON responses here carry a literal `{BASE}` placeholder in the
  `baseUrl` field. Replace it with the deployed Pages URL (sed in a CI step,
  or by hand) before the reviewer tries to open a chapter. The alternative
  is to have the client treat a missing/relative `baseUrl` as "same origin
  as api_url", which is a reasonable client-side change but out of scope
  for this build.

- Content ratings, tags, languages: this source advertises `en` and `es`
  translations but every chapter is only in `en`. That matches the on-disk
  files and is honest for the demo scope.
