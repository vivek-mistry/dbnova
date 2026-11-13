<?php

namespace App\Services\Import;

use RuntimeException;

/**
 * An upload was refused or a chunk was invalid. The message is safe to
 * show to the user.
 */
class UploadException extends RuntimeException {}
