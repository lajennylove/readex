<?php

namespace App\Pages;

use RuntimeException;

/** The @Home lookup got 5xx, 429 or 403: MangaDex is down or limiting the phone, not "chapter removed" (spec §13). */
final class SourceTrouble extends RuntimeException {}
