<?php

namespace App\Models;

use App\Enums\ExportFormat;
use App\Enums\ExportStatus;
use Database\Factories\ExportJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * One export of a database (or some of its tables) to a file. Queue jobs
 * carry only this model's id; progress and the heartbeat are written here.
 *
 * @property string $id
 * @property int $user_id
 * @property string $database_name
 * @property list<string>|null $tables
 * @property ExportFormat $format
 * @property array<string, mixed>|null $options
 * @property ExportStatus $status
 * @property string $file_name
 * @property int $estimated_bytes
 * @property int $bytes_written
 * @property int $file_bytes
 * @property string|null $last_error
 * @property bool $cancel_requested
 * @property int|null $worker_pid
 * @property array{username: string, password: string}|null $credentials
 * @property Carbon|null $heartbeat_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $user
 */
#[Fillable([
    'user_id', 'database_name', 'tables', 'format', 'options', 'status', 'file_name', 'estimated_bytes',
    'bytes_written', 'file_bytes', 'last_error', 'cancel_requested', 'worker_pid', 'credentials',
    'heartbeat_at', 'started_at', 'finished_at', 'expires_at',
])]
#[Hidden(['credentials'])]
class ExportJob extends Model
{
    /** @use HasFactory<ExportJobFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tables' => 'array',
            'format' => ExportFormat::class,
            'options' => 'array',
            'status' => ExportStatus::class,
            'estimated_bytes' => 'integer',
            'bytes_written' => 'integer',
            'file_bytes' => 'integer',
            'cancel_requested' => 'boolean',
            'worker_pid' => 'integer',
            'credentials' => 'encrypted:array',
            'heartbeat_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
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
     * Where the file is written. The user's names never appear in the path.
     */
    public function path(): string
    {
        return config()->string('dbnova.paths.exports').'/'.$this->id.'.'.$this->format->extension();
    }

    /**
     * Whether the finished file can still be downloaded.
     */
    public function isDownloadable(): bool
    {
        return $this->status === ExportStatus::Completed
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && is_file($this->path());
    }

    /**
     * What the UI polls: status and progress, never credentials. A
     * downloadable export gets a short-lived signed link.
     *
     * @return array<string, mixed>
     */
    public function toStatus(): array
    {
        $downloadUrl = null;

        if ($this->isDownloadable()) {
            $linkExpiry = now()->addMinutes(config()->integer('dbnova.retention.export_link_minutes'));
            $downloadUrl = URL::temporarySignedRoute(
                'exports.download',
                $this->expires_at !== null && $this->expires_at->lessThan($linkExpiry) ? $this->expires_at : $linkExpiry,
                ['export' => $this->id],
            );
        }

        return [
            'id' => $this->id,
            'database' => $this->database_name,
            'tables' => $this->tables,
            'format' => $this->format->value,
            'options' => $this->options ?? (object) [],
            'status' => $this->status->value,
            'fileName' => $this->file_name,
            'estimatedBytes' => $this->estimated_bytes,
            'bytesWritten' => $this->bytes_written,
            'fileBytes' => $this->file_bytes,
            'lastError' => $this->last_error,
            'cancelRequested' => $this->cancel_requested,
            'downloadUrl' => $downloadUrl,
            'startedAt' => $this->started_at?->toIso8601String(),
            'finishedAt' => $this->finished_at?->toIso8601String(),
            'expiresAt' => $this->expires_at?->toIso8601String(),
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
