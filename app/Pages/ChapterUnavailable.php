<?php

namespace App\Pages;

use RuntimeException;

/** The source lists no @Home pages for the chapter (external or removed). */
final class ChapterUnavailable extends RuntimeException {}
