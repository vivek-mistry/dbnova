<?php

namespace App\Models;

use App\Enums\UploadStatus;
use Database\Factories\UploadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A resumable, chunked file upload. Chunks are written at their byte
 * offset into one file (path()), and a marker file per received chunk
 * (in chunkDirectory()) records progress, so no assembly step is needed.
 * The user's filename is metadata only and never part of a path.
 *
 * @property string $id
 * @property int $user_id
 * @property string $original_name
 * @property 'sql'|'gz'|'zip' $format
 * @property int $size
 * @property int $chunk_size
 * @property int $total_chunks
 * @property UploadStatus $status
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['user_id', 'original_name', 'format', 'size', 'chunk_size', 'total_chunks', 'status', 'completed_at'])]
class Upload extends Model
{
    /** @use HasFactory<UploadFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'chunk_size' => 'integer',
            'total_chunks' => 'integer',
            'status' => UploadStatus::class,
            'completed_at' => 'datetime',
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
     * The uploaded file on disk.
     */
    public function path(): string
    {
        return config()->string('dbnova.paths.uploads').'/'.$this->id.'.upload';
    }

    /**
     * Holds one empty marker file per received chunk.
     */
    public function chunkDirectory(): string
    {
        return config()->string('dbnova.paths.uploads').'/'.$this->id.'.chunks';
    }

    /**
     * The byte length chunk $index must have.
     */
    public function chunkLength(int $index): int
    {
        return (int) min($this->chunk_size, $this->size - $index * $this->chunk_size);
    }

    /**
     * @return array{id: string, name: string, format: string, size: int, chunkSize: int, totalChunks: int, status: string, createdAt: string}
     */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->original_name,
            'format' => $this->format,
            'size' => $this->size,
            'chunkSize' => $this->chunk_size,
            'totalChunks' => $this->total_chunks,
            'status' => $this->status->value,
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
