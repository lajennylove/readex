#!/usr/bin/env python3
"""
Generate the demo source's static files (JSON responses).

Run from the demo-source/ directory. Idempotent — overwrites.

Content is lorem ipsum (invented titles, placeholder authors, no real author,
work or publisher is referenced). Covers are PNG bytes downloaded from
placehold.co at build time and stored under covers/<id>/cover.256.jpg. Chapter
pages are served directly from placehold.co at render time — the at-home
response hands the client the full page URLs in its `data` array.

Shapes mirror MangaDex's live API responses (verified against
https://api.mangadex.org/manga?limit=1 and /chapter?limit=1 on 2026-10-26) so
a user who swaps SOURCE_URL to https://api.mangadex.org gets the same code
paths exercised unchanged.

Requires only the Python standard library (urllib).
"""
from __future__ import annotations

import json
import shutil
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).parent

# Stable tag UUIDs — hardcoded so re-generating the demo doesn't rotate the
# ids users / tests already reference. Shape mirrors a MangaDex Tag entity:
# {id, type:"tag", attributes:{name:{en:…}, description:{}, group, version}}.
TAGS = [
    {"id": "11111111-1111-4111-8111-111111111111", "name": "Action", "group": "genre"},
    {"id": "22222222-2222-4222-8222-222222222222", "name": "Drama", "group": "genre"},
    {"id": "33333333-3333-4333-8333-333333333333", "name": "Mystery", "group": "genre"},
    {"id": "44444444-4444-4444-8444-444444444444", "name": "Comedy", "group": "genre"},
    {"id": "55555555-5555-4555-8555-555555555555", "name": "Historical", "group": "theme"},
    {"id": "66666666-6666-4666-8666-666666666666", "name": "Sci-Fi", "group": "theme"},
    {"id": "77777777-7777-4777-8777-777777777777", "name": "Slice of Life", "group": "theme"},
    {"id": "88888888-8888-4888-8888-888888888888", "name": "Suspense", "group": "theme"},
]

# Keep title IDs stable so existing cross-references don't break even though
# the human-readable names and content are now lorem ipsum. The `tag_ids`
# field picks 2–3 of the TAGS above per title.
TITLES = [
    {
        "id": "demo-cartographer-journal",
        "title": "Lorem Ipsum Dolor",
        "alt": [{"es": "Dolor Lorem Ipsum"}],
        "description": "Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.",
        "author": "Alpha Writer",
        "artist": "Alpha Writer",
        "year": 2024,
        "status": "ongoing",
        "cover_bg": "1a1a2e",
        "cover_fg": "eeeeee",
        "tag_ids": [
            "11111111-1111-4111-8111-111111111111",  # Action
            "33333333-3333-4333-8333-333333333333",  # Mystery
            "55555555-5555-4555-8555-555555555555",  # Historical
        ],
        "chapters": [
            {"id": "ch-cart-1", "number": "1", "title": "Lorem Primum", "volume": "1"},
            {"id": "ch-cart-2", "number": "2", "title": "Lorem Secundum", "volume": "1"},
        ],
    },
    {
        "id": "demo-signal-across-water",
        "title": "Consectetur Adipiscing",
        "alt": [{"es": "Adipiscing Consectetur"}],
        "description": "Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.",
        "author": "Beta Scribe",
        "artist": "Beta Illustrator",
        "year": 2023,
        "status": "ongoing",
        "cover_bg": "2e1a1a",
        "cover_fg": "eeeeee",
        "tag_ids": [
            "22222222-2222-4222-8222-222222222222",  # Drama
            "66666666-6666-4666-8666-666666666666",  # Sci-Fi
            "88888888-8888-4888-8888-888888888888",  # Suspense
        ],
        "chapters": [
            {"id": "ch-sig-1", "number": "1", "title": "Adipiscing Primum", "volume": "1"},
            {"id": "ch-sig-2", "number": "2", "title": "Adipiscing Secundum", "volume": "1"},
        ],
    },
    {
        "id": "demo-seasons-northern-shelf",
        "title": "Elit Sed Do Eiusmod",
        "alt": [{"es": "Eiusmod Sed Do Elit"}],
        "description": "Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur.",
        "author": "Gamma Author",
        "artist": "Gamma Author",
        "year": 2025,
        "status": "ongoing",
        "cover_bg": "1a2e1a",
        "cover_fg": "eeeeee",
        "tag_ids": [
            "44444444-4444-4444-8444-444444444444",  # Comedy
            "77777777-7777-4777-8777-777777777777",  # Slice of Life
        ],
        "chapters": [
            {"id": "ch-sea-1", "number": "1", "title": "Eiusmod Primum", "volume": "1"},
            {"id": "ch-sea-2", "number": "2", "title": "Eiusmod Secundum", "volume": "1"},
        ],
    },
]

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


# ---------- JSON responses --------------------------------------------------


def tag_entity(tag: dict) -> dict:
    """MangaDex Tag entity. `description` is `{}` on live responses."""
    return {
        "id": tag["id"],
        "type": "tag",
        "attributes": {
            "name": {"en": tag["name"]},
            "description": {},
            "group": tag["group"],
            "version": 1,
        },
        "relationships": [],
    }


def tag_entities_for(t: dict) -> list:
    by_id = {x["id"]: x for x in TAGS}
    return [tag_entity(by_id[tid]) for tid in t.get("tag_ids", [])]


def manga_entity(t: dict, with_people: bool = False) -> dict:
    # Cover file name is `cover` + the `.{size}.jpg` suffix the CoverStore adds.
    # The client resolves <covers_url>/<id>/<fileName>.<size>.jpg; we serve
    # a real PNG at that exact path (PNG bytes under .jpg filename — PHP's
    # getimagesizefromstring detects by magic bytes, not extension).
    rels = [
        {
            "id": f"cover-{t['id']}",
            "type": "cover_art",
            "attributes": {"fileName": "cover"},
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
            "contentRating": "safe",
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
    """Curated-list contract: a list of manga IDs the Home 'This season' row renders.
    A source that opts in serves this; sources that don't 404 and the row hides."""
    return {
        "result": "ok",
        "response": "collection",
        "data": [TITLES[0]["id"], TITLES[1]["id"]],
    }


def detail(t: dict) -> dict:
    return {
        "result": "ok",
        "response": "entity",
        "data": manga_entity(t, with_people=True),
    }


def chapter_entity(t: dict, c: dict) -> dict:
    return {
        "id": c["id"],
        "type": "chapter",
        "attributes": {
            "volume": c["volume"],
            "chapter": c["number"],
            "title": c["title"],
            "translatedLanguage": "en",
            "externalUrl": None,
            "isUnavailable": False,
            "publishAt": NOW,
            "readableAt": NOW,
            "createdAt": NOW,
            "updatedAt": NOW,
            "version": 1,
            "pages": PAGES_PER_CHAPTER,
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
    """Served at /api/manga.json. Backs both:
    - Home "Popular new titles" (GET /manga?order[followedCount]=desc...)
    - Home "Latest updates" second call (GET /manga?ids[]=... for titles)
    - Search tab's default list (GET /manga?title=... — static source ignores
      the query so it always returns the same 3 titles).
    """
    return {
        "result": "ok",
        "response": "collection",
        "data": [manga_entity(t) for t in TITLES],
        "limit": len(TITLES),
        "offset": 0,
        "total": len(TITLES),
    }


def chapter_collection() -> dict:
    """Served at /api/chapter.json. Backs the Home "Latest updates" first call
    (GET /chapter?order[readableAt]=desc...). Six chapters, newest first — the
    client takes the newest chapter per manga for the row."""
    all_chapters = []
    for t in TITLES:
        for c in t["chapters"]:
            all_chapters.append((t, c))
    # Newest first — Ch. 2 of each title before Ch. 1 of the same title.
    all_chapters.sort(key=lambda pair: pair[1]["number"], reverse=True)
    data = [chapter_entity(t, c) for t, c in all_chapters]
    return {
        "result": "ok",
        "response": "collection",
        "data": data,
        "limit": len(data),
        "offset": 0,
        "total": len(data),
    }


def tag_collection() -> dict:
    """Served at /api/manga/tag.json."""
    return {
        "result": "ok",
        "response": "collection",
        "data": [tag_entity(x) for x in TAGS],
        "limit": len(TAGS),
        "offset": 0,
        "total": len(TAGS),
    }


def at_home(t: dict, c: dict) -> dict:
    # Pages are served externally by placehold.co. We embed the full
    # placehold.co URL in each `data[]` entry; AtHomeRepository::pageUrls()
    # keeps absolute URLs verbatim (otherwise concatenates under baseUrl).
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
    # Wipe any old SVG assets from previous builds.
    for subdir in ("covers", "data", "data-saver"):
        p = ROOT / subdir
        if p.exists():
            shutil.rmtree(p)

    write(ROOT / "api/manga/_catalog.json", json_dump(catalog()))
    write(ROOT / "api/manga/_bare_manga", json_dump(catalog()))
    write(ROOT / "api/manga/_seasonal.json", json_dump(seasonal()))
    # The dynamic query endpoints Home + Search hit. Static source: query
    # params are dropped by GH Pages; one file serves every variant.
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
        # Download the cover PNG from placehold.co at build time and store it
        # at the exact path CoverStore::path() expects: covers/<id>/<file>.256.jpg.
        print(f"  fetching cover for {t['id']}...")
        png = download_cover(cover_url(t))
        write_bytes(ROOT / f"covers/{t['id']}/cover.{COVER_SIZE_SUFFIX}.jpg", png)


if __name__ == "__main__":
    build()
    print("demo-source: built (placehold.co + lorem ipsum + MangaDex envelope)")
