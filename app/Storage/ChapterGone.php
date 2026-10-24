<?php

namespace App\Storage;

use RuntimeException;

/** The chapter isn't stored: never begun, or Clear image cache / the cap dropped it meanwhile. */
final class ChapterGone extends RuntimeException {}
