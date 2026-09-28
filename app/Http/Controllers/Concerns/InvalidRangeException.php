<?php

namespace App\Http\Controllers\Concerns;

use RuntimeException;

/**
 * Thrown by StreamLocalFile::parseRangeOrThrow() when a Range header
 * is syntactically valid but the byte range falls outside the file.
 *
 * Maps to a 416 Range Not Satisfiable response per RFC 7233 §4.4.
 */
class InvalidRangeException extends RuntimeException {}
