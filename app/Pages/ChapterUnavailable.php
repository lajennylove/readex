<?php

namespace App\Pages;

use RuntimeException;

/** MangaDex lists no @Home pages for the chapter (external or removed). */
final class ChapterUnavailable extends RuntimeException {}
