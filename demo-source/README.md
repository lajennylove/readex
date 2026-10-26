# ReaDex demo content source

A static GitHub Pages site that serves a tiny, invented catalog shaped like
the HTTP contract ReaDex's built-in adapter expects. Its only purpose is to
give Apple App Review a working endpoint so the app can be exercised
end-to-end without the reviewer touching Setup.

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

## Use

The review build points the app's single API endpoint at this site via
environment variables. Reviewers don't paste anything into Setup — they
launch the app and it already fetches from the demo.

For the review build (`.env.production`, or whatever your deploy env uses):

    SOURCE_API_URL=https://<owner>.github.io/<repo>/demo-source/api
    SOURCE_COVERS_URL=https://<owner>.github.io/<repo>/demo-source/covers
    SOURCE_REPORT_URL=https://<owner>.github.io/<repo>/demo-source/report

For the normal production build, leave those env vars unset and `config/source.php`
falls through to its defaults.

See `../docs/TESTFLIGHT.md` for the full App Store Connect checklist.

## Rebuild

    python3 build.py

Idempotent. No external dependencies. Generates:

- Catalog + per-title JSON responses under `api/`.
- Cover SVGs under `covers/<title-id>/`.
- Page SVGs under `data/<chapter-id>/` and `data-saver/<chapter-id>/`.

## How single-endpoint sourcing works end-to-end

- `config('source.api_url')` is the single hostname everything else hangs
  off. When the review build sets `SOURCE_API_URL`, every GET goes to the
  Pages origin.
- The static JSON for `/at-home/server/<chapter>` returns an empty `baseUrl`.
  `AtHomeRepository::resolveBaseUrl()` sees that and falls back to
  `source.api_url`, so page images resolve to the Pages origin too. No
  deploy-time rewriting of the demo JSON is needed.
- The demo advertises `en` translations only, which matches the on-disk SVGs.

## Content ratings, tags, languages

Honest for the demo scope: safe, generic, English-only.
