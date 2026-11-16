<?php

namespace App\Enums;

enum UploadStatus: string
{
    case Uploading = 'uploading';
    case Uploaded = 'uploaded';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
