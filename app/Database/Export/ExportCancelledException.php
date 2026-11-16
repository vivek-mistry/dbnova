<?php

namespace App\Database\Export;

use RuntimeException;

/**
 * Thrown from a progress callback to stop an export the user cancelled.
 */
class ExportCancelledException extends RuntimeException {}
