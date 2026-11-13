<?php

namespace App\Database\Import;

use RuntimeException;

/**
 * The uploaded file cannot be read as SQL (wrong type, corrupt, truncated
 * or an unsafe archive). The message is safe to show to the user.
 */
class SourceException extends RuntimeException {}
