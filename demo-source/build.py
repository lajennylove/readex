#!/usr/bin/env python3
"""
Generate the demo source's static files (JSON responses + SVG covers + SVG pages).

Run from the demo-source/ directory. Idempotent — overwrites.

Content: three invented titles, each with two chapters of four plain SVG pages.
No real-world author, work, or publisher is referenced. Covers and pages are
geometric placeholders labelled "Volume X · Chapter Y · Page Z".
"""
from __future__ import annotations
import json
import os
from pathlib import Path

ROOT = Path(__file__).parent

TITLES = [
    {
        "id": "demo-cartographer-journal",
        "title": "The Cartographer's Journal",
        "alt": [{"es": "El cuaderno del cartógrafo"}],
        "description": "A quiet travelogue of a surveyor charting an invented coastline.",
        "author": "Isabel Montcalm",
        "artist": "Isabel Montcalm",
        "year": 2024,
        "status": "ongoing",
        "cover": "cover.svg",
        "cover_fill": "#3B5F7A",
        "chapters": [
            {"id": "ch-cart-1", "number": "1", "title": "Tide tables", "volume": "1"},
            {"id": "ch-cart-2", "number": "2", "title": "Dead reckoning", "volume": "1"},
        ],
    },
    {
        "id": "demo-signal-across-water",
        "title": "Signal Across the Water",
        "alt": [{"es": "Señal a través del agua"}],
        "description": "Short stories of a lighthouse keeper exchanging letters by lantern.",
        "author": "Teodor Vonk",
        "artist": "Marina Hale",
        "year": 2023,
        "status": "ongoing",
        "cover": "cover.svg",
        "cover_fill": "#7A4A3B",
        "chapters": [
            {"id": "ch-sig-1", "number": "1", "title": "First lamp", "volume": "1"},
            {"id": "ch-sig-2", "number": "2", "title": "Fog bell", "volume": "1"},
        ],
    },
    {
        "id": "demo-seasons-northern-shelf",
        "title": "Seasons on the Northern Shelf",
        "alt": [{"es": "Estaciones de la plataforma norte"}],
        "description": "A year with a research crew on an invented sub-arctic platform.",
        "author": "Rune Oftedal",
        "artist": "Rune Oftedal",
        "year": 2025,
        "status": "ongoing",
        "cover": "cover.svg",
        "cover_fill": "#4A7A5F",
        "chapters": [
            {"id": "ch-sea-1", "number": "1", "title": "Thaw", "volume": "1"},
            {"id": "ch-sea-2", "number": "2", "title": "Midnight sun", "volume": "1"},
        ],
    },
]

GROUP_ID = "demo-group-1"
GROUP_NAME = "Demo Translators"
NOW = "2026-10-26T00:00:00+00:00"


def write(path: Path, body: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(body)


def json_dump(obj) -> str:
    return json.dumps(obj, ensure_ascii=False, separators=(",", ": "), indent=2)


# ---------- JSON responses --------------------------------------------------


def manga_entity(t: dict, with_people: bool = False) -> dict:
    rels = [
        {
            "id": f"cover-{t['id']}",
            "type": "cover_art",
            "attributes": {"fileName": t["cover"]},
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
            "pages": 4,
            "externalUrl": None,
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


def at_home(c_id: str) -> dict:
    # baseUrl is intentionally empty: this demo is a single-endpoint source,
    # not an @Home-style CDN layer. AtHomeRepository::resolveBaseUrl() falls
    # back to source.api_url when baseUrl is empty or non-absolute, which puts
    # the images at the Pages origin where they actually live.
    return {
        "result": "ok",
        "baseUrl": "",
        "chapter": {
            "hash": c_id,
            "data": [f"{i:03d}.svg" for i in range(1, 5)],
            "dataSaver": [f"{i:03d}.svg" for i in range(1, 5)],
        },
    }


# ---------- SVG assets ------------------------------------------------------


def cover_svg(t: dict) -> str:
    fill = t["cover_fill"]
    return f"""<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 600" width="400" height="600">
  <rect width="400" height="600" fill="{fill}"/>
  <rect x="24" y="24" width="352" height="552" fill="none" stroke="#F1EBD9" stroke-width="2"/>
  <text x="200" y="280" font-family="Georgia, serif" font-size="28" font-weight="bold" fill="#F1EBD9" text-anchor="middle">{t['title']}</text>
  <text x="200" y="320" font-family="Georgia, serif" font-size="14" fill="#F1EBD9" text-anchor="middle" opacity="0.85">{t['author']}</text>
  <text x="200" y="560" font-family="Georgia, serif" font-size="12" fill="#F1EBD9" text-anchor="middle" opacity="0.6">Demo source · placeholder cover</text>
</svg>
"""


def page_svg(title: str, c_num: str, p: int) -> str:
    return f"""<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 1200" width="800" height="1200">
  <rect width="800" height="1200" fill="#F7F4EC"/>
  <rect x="40" y="40" width="720" height="1120" fill="none" stroke="#4A4038" stroke-width="2"/>
  <text x="400" y="520" font-family="Georgia, serif" font-size="36" fill="#4A4038" text-anchor="middle">{title}</text>
  <text x="400" y="580" font-family="Georgia, serif" font-size="26" fill="#4A4038" text-anchor="middle">Chapter {c_num} · Page {p}</text>
  <text x="400" y="1130" font-family="Georgia, serif" font-size="14" fill="#4A4038" text-anchor="middle" opacity="0.6">Demo source · placeholder page</text>
</svg>
"""


# ---------- Build -----------------------------------------------------------


def build() -> None:
    write(ROOT / "api/manga/_catalog.json", json_dump(catalog()))
    # GH Pages serves bare paths too — we also drop a file literally named
    # `manga` (no extension) that mirrors the catalog, so the production
    # client's `GET /manga` resolves without rewrites.
    write(ROOT / "api/manga/_bare_manga", json_dump(catalog()))

    for t in TITLES:
        write(ROOT / f"api/manga/{t['id']}.json", json_dump(detail(t)))
        write(ROOT / f"api/manga/{t['id']}/feed.json", json_dump(feed(t)))
        for c in t["chapters"]:
            write(
                ROOT / f"api/at-home/server/{c['id']}.json",
                json_dump(at_home(c["id"])),
            )
            for i in range(1, 5):
                page = page_svg(t["title"], c["number"], i)
                write(ROOT / f"data/{c['id']}/{i:03d}.svg", page)
                write(ROOT / f"data-saver/{c['id']}/{i:03d}.svg", page)
        write(ROOT / f"covers/{t['id']}/{t['cover']}", cover_svg(t))

    write(ROOT / "api/manga/tag.json", json_dump({"result": "ok", "response": "collection", "data": []}))


if __name__ == "__main__":
    build()
    print("demo-source: built")
