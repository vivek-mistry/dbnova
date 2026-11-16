<?php

namespace App\Database\Import;

use RuntimeException;

/**
 * The import stopped before sending a statement the import policy refuses.
 * Preflight normally catches these; this is the second line of defence.
 */
class ImportRefusedException extends RuntimeException {}
