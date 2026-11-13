<?php

namespace App\Database\Import;

use RuntimeException;

/**
 * Thrown from a progress callback to stop a scan or import the user cancelled.
 */
class ImportCancelledException extends RuntimeException {}
