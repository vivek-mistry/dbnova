<?php

namespace App\Services\Import;

/**
 * A chunk could not be written because the disk is full. The chunk is not
 * recorded, so the upload resumes once there is room.
 */
class DiskFullException extends UploadException {}
