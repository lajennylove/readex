<?php

// Recorded by `php artisan mangadex:record-fixtures` (real API, through the RequestController).
// name => [api.mangadex.org path, query]. Later tasks add their entries here and record them with --only.

$allRatings = ['safe', 'suggestive', 'erotica', 'pornographic'];

return [

    'tags' => ['/manga/tag', []],

    // Mixed: es / es-la series and English-only ones (Review Focus 1).
    'search-one-punch' => ['/manga', [
        'title' => 'one punch',
        'limit' => 20,
        'offset' => 0,
        'contentRating' => ['safe', 'suggestive'],
        'includes' => ['cover_art'],
        'order' => ['relevance' => 'desc'],
    ]],

    // Romanized-Chinese title only, altTitles [], availableTranslatedLanguages [], es-la description only.
    'manga-nova' => ['/manga/8a45c930-32d1-423e-bffd-9e8d71312134', ['includes' => ['cover_art']]],

    // 182 chapters: decimal numbers (126.2), 132 without a volume, 3 without a group.
    'feed-one-punch-es-la' => ['/manga/d8a959f7-648e-4c8d-8f23-f1f3f8e129f3/feed', [
        'translatedLanguage' => ['es-la'],
        'limit' => 500,
        'offset' => 0,
        'contentRating' => $allRatings,
        'includes' => ['scanlation_group'],
        'order' => ['volume' => 'asc', 'chapter' => 'asc'],
    ]],

    // Licensed: every chapter is an external link, VIZ and MangaPlus upload the same numbers.
    'feed-chainsaw-man-en' => ['/manga/a77742b1-befd-49a4-bff5-1ad4e6b0ef7b/feed', [
        'translatedLanguage' => ['en'],
        'limit' => 500,
        'offset' => 0,
        'contentRating' => $allRatings,
        'includes' => ['scanlation_group'],
        'order' => ['volume' => 'asc', 'chapter' => 'asc'],
    ]],

    // Oneshot: chapter number and volume are null.
    'feed-oneshot-en' => ['/manga/69141247-f88f-4149-a994-31612cb3eb74/feed', [
        'translatedLanguage' => ['en'],
        'limit' => 500,
        'offset' => 0,
        'contentRating' => $allRatings,
        'includes' => ['scanlation_group'],
        'order' => ['volume' => 'asc', 'chapter' => 'asc'],
    ]],

];
