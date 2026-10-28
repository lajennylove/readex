#!/usr/bin/env python3
"""
Generate the demo source's static files (JSON responses).

Run from the demo-source/ directory. Idempotent — overwrites.

30 lorem-ipsum titles across MangaDex's four content ratings so an App Review
walkthrough can exercise the Browse row + rating filter + tag filter against a
realistic catalog. All text is lorem ipsum; all authors are invented (Alpha
Writer, Beta Illustrator, …); every image is a placehold.co URL — nothing in
this demo references a real work, person or publisher.

Rating distribution (hard-coded in TITLES below so a reviewer can flip the
rating filter and see each category appear/disappear):
  - 22 titles at `safe`
  - 5 titles at `suggestive`
  - 2 titles at `erotica`
  - 1 title at `pornographic`

Erotica + pornographic titles also carry the `Mature` tag, so a tag-based
filter catches them in addition to the rating filter. Their content is still
just lorem ipsum + placehold.co placeholders — the ratings exist only so the
filter has something to work on.

Shapes mirror MangaDex's live API responses (verified against
https://api.mangadex.org/manga?limit=1 and /chapter?limit=1 on 2026-10-26) so
a user who swaps SOURCE_URL to https://api.mangadex.org gets the same code
paths exercised unchanged. No new fields were introduced for this expansion.

Stable identifiers:
  - Title IDs: `demo-01` … `demo-30` (zero-padded ordinal).
  - Chapter IDs: `ch-<nn>-a` and `ch-<nn>-b` (b only on titles 1-15).
  - Tag UUIDs: see TAGS below — hardcoded so regeneration doesn't rotate ids
    that users or tests already reference.

Requires only the Python standard library (urllib).
"""
from __future__ import annotations

import json
import shutil
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).parent

# ---------- Tags ------------------------------------------------------------

TAGS = [
    {"id": "11111111-1111-4111-8111-111111111111", "name": "Action", "group": "genre"},
    {"id": "22222222-2222-4222-8222-222222222222", "name": "Drama", "group": "genre"},
    {"id": "33333333-3333-4333-8333-333333333333", "name": "Mystery", "group": "genre"},
    {"id": "44444444-4444-4444-8444-444444444444", "name": "Comedy", "group": "genre"},
    {"id": "55555555-5555-4555-8555-555555555555", "name": "Historical", "group": "theme"},
    {"id": "66666666-6666-4666-8666-666666666666", "name": "Sci-Fi", "group": "theme"},
    {"id": "77777777-7777-4777-8777-777777777777", "name": "Slice of Life", "group": "theme"},
    {"id": "88888888-8888-4888-8888-888888888888", "name": "Suspense", "group": "theme"},
    {"id": "99999999-9999-4999-8999-999999999999", "name": "Mature", "group": "content"},
    # Matches MangaDex's actual Self-Published tag UUID so a user who swaps to MangaDex sees
    # the Home row backed by the real catalog; demo titles below carry it too.
    {"id": "891cf039-b895-47f0-9229-bef4c96eccd4", "name": "Self-Published", "group": "format"},
]

TAG_BY_NAME = {t["name"]: t["id"] for t in TAGS}


def tag(*names: str) -> list[str]:
    return [TAG_BY_NAME[n] for n in names]


# ---------- Titles ----------------------------------------------------------
#
# Tag distribution across 30 titles (counted after building TITLES below):
#   Action        16    Drama           12    Mystery           8    Comedy    10
#   Historical     5    Sci-Fi           7    Slice of Life    10    Suspense   6
#   Mature         3    (on all erotica + pornographic rows)
#
# Rating distribution:
#   safe 22 · suggestive 5 · erotica 2 · pornographic 1
#
# Lorem names are varied phrases from classical Lorem Ipsum so a reviewer sees
# a non-trivial list, not three copies of "Lorem Ipsum".

AUTHORS = [
    "Alpha Writer", "Beta Scribe", "Gamma Author", "Delta Pen",
    "Epsilon Quill", "Zeta Script", "Eta Chronicler", "Theta Draft",
    "Iota Composer", "Kappa Narrator",
]
ARTISTS = [
    "Alpha Illustrator", "Beta Inker", "Gamma Line", "Delta Brush",
    "Epsilon Palette", "Zeta Studio", "Eta Panel", "Theta Ink",
    "Iota Canvas", "Kappa Frame",
]

# Palette of cover backgrounds — cycled, so adjacent tiles don't clash.
COVER_PALETTE = [
    ("1a1a2e", "eeeeee"), ("2e1a1a", "eeeeee"), ("1a2e1a", "eeeeee"),
    ("2e2e1a", "222222"), ("1a2e2e", "222222"), ("2e1a2e", "eeeeee"),
    ("3a2e1a", "eeeeee"), ("1a3a2e", "eeeeee"), ("2e1a3a", "eeeeee"),
    ("3a1a2e", "eeeeee"), ("1a2e3a", "eeeeee"), ("2e3a1a", "222222"),
    ("4a3a2a", "eeeeee"), ("2a4a3a", "eeeeee"), ("3a2a4a", "eeeeee"),
]

TITLES: list[dict] = []


def _add(
    ordinal: int,
    title_text: str,
    description: str,
    rating: str,
    tag_names: list[str],
    year: int,
    chapters: int,
) -> None:
    idx = ordinal - 1
    bg, fg = COVER_PALETTE[idx % len(COVER_PALETTE)]
    author = AUTHORS[idx % len(AUTHORS)]
    artist = ARTISTS[(idx + 3) % len(ARTISTS)]
    chapter_entries = [
        {
            "id": f"ch-{ordinal:02d}-a",
            "number": "1",
            "title": "Lorem Primum",
            "volume": "1",
        }
    ]
    if chapters >= 2:
        chapter_entries.append({
            "id": f"ch-{ordinal:02d}-b",
            "number": "2",
            "title": "Lorem Secundum",
            "volume": "1",
        })
    TITLES.append({
        "id": f"demo-{ordinal:02d}",
        "ordinal": ordinal,
        "title": title_text,
        "alt": [{"es": title_text + " (es)"}],
        "description": description,
        "author": author,
        "artist": artist,
        "year": year,
        "status": "ongoing",
        "cover_bg": bg,
        "cover_fg": fg,
        "content_rating": rating,
        "tag_ids": tag(*tag_names),
        "chapters": chapter_entries,
    })


_LOREM = "Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua."
_LOREM2 = "Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat."
_LOREM3 = "Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur."


# -- safe (22) ---------------------------------------------------------------
_add(1,  "Lorem Ipsum Dolor",        _LOREM,  "safe", ["Action", "Mystery", "Historical"],       2024, 2)
_add(2,  "Consectetur Adipiscing",   _LOREM2, "safe", ["Action", "Sci-Fi", "Suspense"],          2023, 2)
_add(3,  "Elit Sed Do Eiusmod",      _LOREM3, "safe", ["Comedy", "Slice of Life", "Self-Published"],  2025, 2)
_add(4,  "Tempor Incididunt",        _LOREM,  "safe", ["Action", "Drama"],                       2022, 2)
_add(5,  "Labore Et Dolore",         _LOREM2, "safe", ["Mystery", "Suspense", "Historical"],     2024, 2)
_add(6,  "Magna Aliqua",             _LOREM3, "safe", ["Comedy", "Slice of Life", "Mystery"],    2024, 2)
_add(7,  "Ut Enim Ad Minim",         _LOREM,  "safe", ["Action", "Sci-Fi"],                      2025, 2)
_add(8,  "Veniam Quis Nostrud",      _LOREM2, "safe", ["Drama", "Slice of Life"],                2023, 2)
_add(9,  "Exercitation Ullamco",     _LOREM3, "safe", ["Action", "Comedy", "Sci-Fi"],            2024, 2)
_add(10, "Laboris Nisi Aliquip",     _LOREM,  "safe", ["Mystery", "Slice of Life", "Self-Published"], 2022, 2)
_add(11, "Ex Ea Commodo",            _LOREM2, "safe", ["Action", "Sci-Fi", "Historical"],        2024, 2)
_add(12, "Consequat Duis Aute",      _LOREM3, "safe", ["Comedy", "Slice of Life"],               2025, 2)
_add(13, "Irure Dolor",              _LOREM,  "safe", ["Action", "Suspense"],                    2023, 2)
_add(14, "Reprehenderit Voluptate",  _LOREM2, "safe", ["Drama", "Sci-Fi"],                       2024, 2)
_add(15, "Velit Esse Cillum",        _LOREM3, "safe", ["Action", "Comedy", "Slice of Life"],     2025, 2)
_add(16, "Fugiat Nulla Pariatur",    _LOREM,  "safe", ["Action", "Mystery"],                      2024, 1)
_add(17, "Excepteur Sint Occaecat",  _LOREM2, "safe", ["Drama", "Slice of Life", "Comedy", "Self-Published"], 2023, 1)
_add(18, "Cupidatat Non Proident",   _LOREM3, "safe", ["Historical", "Comedy"],                   2022, 1)
_add(19, "Sunt In Culpa",            _LOREM,  "safe", ["Action", "Sci-Fi", "Suspense"],          2024, 1)
_add(20, "Qui Officia Deserunt",     _LOREM2, "safe", ["Comedy", "Slice of Life", "Action"],     2025, 1)
_add(21, "Mollit Anim Id Est",       _LOREM3, "safe", ["Action", "Drama"],                       2024, 1)
_add(22, "Laborum Sed Perspiciatis", _LOREM,  "safe", ["Mystery", "Comedy"],                     2023, 1)

# -- suggestive (5) ----------------------------------------------------------
_add(23, "Unde Omnis Iste",          _LOREM2, "suggestive", ["Drama", "Suspense"],                            2024, 1)
_add(24, "Natus Error Voluptatem",   _LOREM3, "suggestive", ["Action", "Drama", "Mystery"],                   2023, 1)
_add(25, "Accusantium Doloremque",   _LOREM,  "suggestive", ["Slice of Life", "Drama"],                       2024, 1)
_add(26, "Laudantium Totam Rem",     _LOREM2, "suggestive", ["Action", "Suspense"],                           2025, 1)
_add(27, "Aperiam Eaque Ipsa",       _LOREM3, "suggestive", ["Historical", "Drama"],                          2022, 1)

# -- erotica (2) + Mature tag ------------------------------------------------
_add(28, "Quae Ab Illo Inventore",   _LOREM,  "erotica",       ["Drama", "Mature"],                           2024, 1)
_add(29, "Veritatis Et Quasi",       _LOREM2, "erotica",       ["Slice of Life", "Drama", "Mature"],          2023, 1)

# -- pornographic (1) + Mature tag -------------------------------------------
_add(30, "Architecto Beatae Vitae",  _LOREM3, "pornographic", ["Suspense", "Mature"],                         2024, 1)


# A handful of chapters whose images are NOT on this source — the source only
# tells the reader where to go, and the client opens that URL in its in-app
# WebView. On the detail screen these show "Opens on <host>" instead of a page
# count; tapping does not download, just opens the page. The targets live under
# /external/ next to this generated api/ tree, so they ship from the same
# GitHub Pages deploy with no extra hosting.
_EXTERNAL_HOST = "https://lajennylove.github.io/readex/external"
EXTERNAL_CHAPTERS = {
    ("demo-02", "ch-02-a"): f"{_EXTERNAL_HOST}/demo-02/ch-a.html",
    ("demo-07", "ch-07-a"): f"{_EXTERNAL_HOST}/demo-07/ch-a.html",
}
for t in TITLES:
    for c in t["chapters"]:
        url = EXTERNAL_CHAPTERS.get((t["id"], c["id"]))
        if url is not None:
            c["external_url"] = url


# ---------- Common bits -----------------------------------------------------

GROUP_ID = "demo-group-1"
GROUP_NAME = "Demo Translators"
NOW = "2026-10-26T00:00:00+00:00"

PLACEHOLD = "https://placehold.co"
COVER_SIZE_PATH = "400x600"
COVER_SIZE_SUFFIX = 256
PAGE_SIZE_PATH = "800x1200"
PAGE_BG = "222222"
PAGE_FG = "eeeeee"
PAGES_PER_CHAPTER = 3


def write(path: Path, body: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(body)


def write_bytes(path: Path, body: bytes) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(body)


def json_dump(obj) -> str:
    return json.dumps(obj, ensure_ascii=False, separators=(",", ": "), indent=2)


def cover_url(t: dict) -> str:
    text = urllib.parse.quote_plus(t["title"])
    return f"{PLACEHOLD}/{COVER_SIZE_PATH}/{t['cover_bg']}/{t['cover_fg']}.png?text={text}"


def page_url(t: dict, c: dict, page_num: int) -> str:
    text = urllib.parse.quote_plus(f"{t['title']}\nCh. {c['number']} · Page {page_num}")
    return f"{PLACEHOLD}/{PAGE_SIZE_PATH}/{PAGE_BG}/{PAGE_FG}.png?text={text}"


# Each chapter gets its own readableAt timestamp — titles with higher ordinals
# are considered newer, title 30 Ch.2 is "now", title 1 Ch.1 is oldest. Chunk
# per title so chapter B is one hour newer than chapter A.
def readable_at_for(t: dict, c: dict) -> str:
    minutes_from_now = (31 - t["ordinal"]) * 120 + (0 if c["number"] == "2" else 60)
    hours = minutes_from_now // 60
    mins = minutes_from_now % 60
    return f"2026-10-{26 - (hours // 24):02d}T{(23 - (hours % 24)):02d}:{(59 - mins):02d}:00+00:00"


# ---------- JSON responses --------------------------------------------------


def tag_entity(x: dict) -> dict:
    return {
        "id": x["id"],
        "type": "tag",
        "attributes": {
            "name": {"en": x["name"]},
            "description": {},
            "group": x["group"],
            "version": 1,
        },
        "relationships": [],
    }


def tag_entities_for(t: dict) -> list:
    by_id = {x["id"]: x for x in TAGS}
    return [tag_entity(by_id[tid]) for tid in t.get("tag_ids", [])]


def manga_entity(t: dict, with_people: bool = False) -> dict:
    rels = [
        {
            "id": f"cover-{t['id']}",
            "type": "cover_art",
            # Absolute image URL. An agnostic reader can't know a `source.covers_url`
            # ahead of time (the user types one URL — the API), so the demo embeds the
            # full image URL in the API response. CoverStore::url() short-circuits on
            # http(s):// and skips the covers_url concatenation entirely.
            "attributes": {"fileName": cover_url(t)},
        }
    ]
    if with_people:
        rels += [
            {
                "id": f"author-{t['id']}",
                "type": "author",
                "attributes": {"name": t["author"]},
            },
            {
                "id": f"artist-{t['id']}",
                "type": "artist",
                "attributes": {"name": t["artist"]},
            },
        ]
    return {
        "id": t["id"],
        "type": "manga",
        "attributes": {
            "title": {"en": t["title"]},
            "altTitles": t["alt"],
            "description": {"en": t["description"]},
            "isLocked": False,
            "links": {},
            "originalLanguage": "en",
            "lastVolume": "",
            "lastChapter": "",
            "publicationDemographic": None,
            "status": t["status"],
            "year": t["year"],
            "contentRating": t["content_rating"],
            "tags": tag_entities_for(t),
            "state": "published",
            "chapterNumbersResetOnNewVolume": False,
            "createdAt": NOW,
            "updatedAt": NOW,
            "version": 1,
            "availableTranslatedLanguages": ["en", "es"],
            "latestUploadedChapter": t["chapters"][-1]["id"] if t.get("chapters") else None,
        },
        "relationships": rels,
    }


def catalog() -> dict:
    return {
        "result": "ok",
        "response": "collection",
        "data": [manga_entity(t) for t in TITLES],
        "limit": len(TITLES),
        "offset": 0,
        "total": len(TITLES),
    }


def seasonal() -> dict:
    # A curated list a reviewer can see "This season" render from. Pick a
    # handful across tags/ratings — all safe here, since the curated row is
    # the first non-skeleton surface in Home.
    picks = [TITLES[0]["id"], TITLES[2]["id"], TITLES[6]["id"], TITLES[14]["id"], TITLES[20]["id"]]
    return {"result": "ok", "response": "collection", "data": picks}


def detail(t: dict) -> dict:
    return {
        "result": "ok",
        "response": "entity",
        "data": manga_entity(t, with_people=True),
    }


def chapter_entity(t: dict, c: dict) -> dict:
    readable = readable_at_for(t, c)
    external_url = c.get("external_url")
    return {
        "id": c["id"],
        "type": "chapter",
        "attributes": {
            "volume": c["volume"],
            "chapter": c["number"],
            "title": c["title"],
            "translatedLanguage": "en",
            # A real upstream sends `externalUrl` for chapters hosted off-site (the
            # upstream has only the metadata, not the images). The client then opens
            # the URL in its WebView reader instead of fetching page images via
            # @Home. Demo mirrors that path on a couple of chapters so the review
            # walkthrough exercises Browser::inApp alongside the normal reader.
            "externalUrl": external_url,
            "isUnavailable": False,
            # The real upstream reports 0 pages when the chapter is external
            # (nothing to serve). Match that so the detail screen renders the
            # right affordance ("Opens on …").
            "publishAt": readable,
            "readableAt": readable,
            "createdAt": readable,
            "updatedAt": readable,
            "version": 1,
            "pages": 0 if external_url else PAGES_PER_CHAPTER,
        },
        "relationships": [
            {
                "id": GROUP_ID,
                "type": "scanlation_group",
                "attributes": {
                    "name": GROUP_NAME,
                    "altNames": [],
                    "locked": False,
                    "website": None,
                    "focusedLanguages": ["en"],
                    "official": False,
                    "verified": False,
                    "inactive": False,
                    "createdAt": NOW,
                    "updatedAt": NOW,
                    "version": 1,
                },
            },
            {"id": t["id"], "type": "manga"},
        ],
    }


def feed(t: dict) -> dict:
    data = [chapter_entity(t, c) for c in t["chapters"]]
    return {
        "result": "ok",
        "response": "collection",
        "data": data,
        "limit": len(data),
        "offset": 0,
        "total": len(data),
    }


def manga_collection() -> dict:
    """Served at /api/manga.json. Backs Home 'Popular new titles', the Latest
    row's second call (ids[]=), and the Search tab's default list. A static
    source can't filter — one file serves every variant; the client applies
    content-rating + tag filters locally."""
    return {
        "result": "ok",
        "response": "collection",
        "data": [manga_entity(t) for t in TITLES],
        "limit": len(TITLES),
        "offset": 0,
        "total": len(TITLES),
    }


def chapter_collection() -> dict:
    """Served at /api/chapter.json. Backs Home 'Latest updates' first call.
    15 chapters, newest readableAt first, cross-section of titles (includes
    adult-rated ones so filter changes are visible there too)."""
    all_pairs: list[tuple[dict, dict]] = []
    for t in TITLES:
        for c in t["chapters"]:
            all_pairs.append((t, c))
    # Newest first: higher ordinal -> newer; chapter "2" newer than "1" within a title.
    all_pairs.sort(key=lambda pair: (pair[0]["ordinal"], 1 if pair[1]["number"] == "2" else 0), reverse=True)
    picked = all_pairs[:15]
    data = [chapter_entity(t, c) for t, c in picked]
    return {
        "result": "ok",
        "response": "collection",
        "data": data,
        "limit": len(data),
        "offset": 0,
        "total": len(data),
    }


def tag_collection() -> dict:
    return {
        "result": "ok",
        "response": "collection",
        "data": [tag_entity(x) for x in TAGS],
        "limit": len(TAGS),
        "offset": 0,
        "total": len(TAGS),
    }


def at_home(t: dict, c: dict) -> dict:
    return {
        "result": "ok",
        "baseUrl": "",
        "chapter": {
            "hash": c["id"],
            "data": [page_url(t, c, i) for i in range(1, PAGES_PER_CHAPTER + 1)],
            "dataSaver": [page_url(t, c, i) for i in range(1, PAGES_PER_CHAPTER + 1)],
        },
    }


def download_cover(url: str) -> bytes:
    with urllib.request.urlopen(url, timeout=20) as resp:
        return resp.read()


# ---------- Build -----------------------------------------------------------


def build() -> None:
    # Wipe any old generated assets from previous builds — the api/ tree is
    # fully regenerated below; covers/ is downloaded fresh.
    for subdir in ("covers", "data", "data-saver", "api"):
        p = ROOT / subdir
        if p.exists():
            shutil.rmtree(p)

    write(ROOT / "api/manga/_catalog.json", json_dump(catalog()))
    write(ROOT / "api/manga/_bare_manga", json_dump(catalog()))
    write(ROOT / "api/manga/_seasonal.json", json_dump(seasonal()))
    write(ROOT / "api/manga.json", json_dump(manga_collection()))
    write(ROOT / "api/chapter.json", json_dump(chapter_collection()))
    write(ROOT / "api/manga/tag.json", json_dump(tag_collection()))

    for t in TITLES:
        write(ROOT / f"api/manga/{t['id']}.json", json_dump(detail(t)))
        write(ROOT / f"api/manga/{t['id']}/feed.json", json_dump(feed(t)))
        for c in t["chapters"]:
            write(
                ROOT / f"api/at-home/server/{c['id']}.json",
                json_dump(at_home(t, c)),
            )
        print(f"  fetching cover for {t['id']}...")
        png = download_cover(cover_url(t))
        write_bytes(ROOT / f"covers/{t['id']}/cover.{COVER_SIZE_SUFFIX}.jpg", png)


if __name__ == "__main__":
    build()
    print(f"demo-source: built {len(TITLES)} titles (placehold.co + lorem ipsum + MangaDex envelope)")
