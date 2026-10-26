#!/usr/bin/env python3
"""
Generate the demo source's static files (JSON responses).

Run from the demo-source/ directory. Idempotent — overwrites.

Content is lorem ipsum (invented titles, placeholder authors, no real author,
work or publisher is referenced). Covers are PNG bytes downloaded from
placehold.co at build time and stored under covers/<id>/cover.256.jpg. Chapter
pages are served directly from placehold.co at render time — the at-home
response hands the client the full page URLs in its `data` array.

Requires only the Python standard library (urllib).
"""
from __future__ import annotations

import json
import shutil
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).parent

# Keep title IDs stable so existing cross-references don't break even though
# the human-readable names and content are now lorem ipsum.
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
            "status": t["status"],
            "year": t["year"],
            "contentRating": "safe",
            "originalLanguage": "en",
            "availableTranslatedLanguages": ["en", "es"],
            "tags": [],
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
            "pages": PAGES_PER_CHAPTER,
            "externalUrl": None,
            "publishAt": NOW,
            "readableAt": NOW,
            "isUnavailable": False,
        },
        "relationships": [
            {"id": t["id"], "type": "manga"},
            {
                "id": GROUP_ID,
                "type": "scanlation_group",
                "attributes": {"name": GROUP_NAME},
            },
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


def at_home(t: dict, c: dict) -> dict:
    # Pages are served externally by placehold.co. We embed the full
    # placehold.co URL in each `data[]` entry and use baseUrl="" so the
    # client's AtHomeRepository::resolveBaseUrl() same-origin fallback
    # applies; the hash directory inside the URL is still /data/<hash>/.
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

    write(ROOT / "api/manga/tag.json", json_dump({"result": "ok", "response": "collection", "data": []}))


if __name__ == "__main__":
    build()
    print("demo-source: built (placehold.co + lorem ipsum)")
