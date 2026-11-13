<?php

namespace App\Models;

use App\Enums\ImportStatus;
use Database\Factories\ImportJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One import of an uploaded SQL file into one database. Queue jobs carry
 * only this model's id; progress and the heartbeat are written here.
 *
 * @property string $id
 * @property int $user_id
 * @property string|null $upload_id
 * @property string $database_name
 * @property ImportStatus $status
 * @property array<string, mixed>|null $options
 * @property array<string, mixed>|null $preflight
 * @property int $bytes_total
 * @property int $bytes_scanned
 * @property int $bytes_streamed
 * @property int $statements_seen
 * @property int $error_count
 * @property string|null $last_error
 * @property int|null $error_line
 * @property bool $cancel_requested
 * @property int|null $worker_pid
 * @property array{username: string, password: string}|null $credentials
 * @property Carbon|null $heartbeat_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Upload|null $upload
 */
#[Fillable([
    'user_id', 'upload_id', 'database_name', 'status', 'options', 'preflight', 'bytes_total', 'bytes_scanned',
    'bytes_streamed', 'statements_seen', 'error_count', 'last_error', 'error_line', 'cancel_requested',
    'worker_pid', 'credentials', 'heartbeat_at', 'started_at', 'finished_at',
])]
#[Hidden(['credentials'])]
class ImportJob extends Model
{
    /** @use HasFactory<ImportJobFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'options' => 'array',
            'preflight' => 'array',
            'bytes_total' => 'integer',
            'bytes_scanned' => 'integer',
            'bytes_streamed' => 'integer',
            'statements_seen' => 'integer',
            'error_count' => 'integer',
            'error_line' => 'integer',
            'cancel_requested' => 'boolean',
            'worker_pid' => 'integer',
            'credentials' => 'encrypted:array',
            'heartbeat_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Upload, $this>
     */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }

    /**
     * What the UI polls: status and honest progress, never credentials.
     *
     * @return array<string, mixed>
     */
    public function toStatus(): array
    {
        return [
            'id' => $this->id,
            'database' => $this->database_name,
            'status' => $this->status->value,
            'fileName' => $this->upload?->original_name,
            'bytesTotal' => $this->bytes_total,
            'bytesScanned' => $this->bytes_scanned,
            'bytesStreamed' => $this->bytes_streamed,
            'statementsSeen' => $this->statements_seen,
            'errorCount' => $this->error_count,
            'lastError' => $this->last_error,
            'errorLine' => $this->error_line,
            'preflight' => $this->preflight,
            'cancelRequested' => $this->cancel_requested,
            'startedAt' => $this->started_at?->toIso8601String(),
            'finishedAt' => $this->finished_at?->toIso8601String(),
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
