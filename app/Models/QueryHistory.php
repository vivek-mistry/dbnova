<?php

namespace App\Models;

use Database\Factories\QueryHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A SQL editor run. Saved queries are history entries with a name.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $database_name
 * @property string $sql
 * @property int $statement_count
 * @property 'ok'|'error' $status
 * @property string|null $error
 * @property float $duration_ms
 * @property string|null $name
 * @property Carbon|null $saved_at
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'database_name', 'sql', 'statement_count', 'status', 'error', 'duration_ms', 'name', 'saved_at'])]
class QueryHistory extends Model
{
    /** @use HasFactory<QueryHistoryFactory> */
    use HasFactory;

    /**
     * History entries are only updated when they are saved.
     */
    public const UPDATED_AT = null;

    /**
     * Unsaved entries kept per user; older ones are pruned.
     */
    public const int KEEP_PER_USER = 200;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_ms' => 'float',
            'saved_at' => 'datetime',
            'created_at' => 'datetime',
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
     * @param  Builder<self>  $query
     */
    public function scopeSaved(Builder $query): void
    {
        $query->whereNotNull('saved_at');
    }

    /**
     * @return array{id: int, database: string|null, sql: string, statementCount: int, status: string, error: string|null, durationMs: float, name: string|null, saved: bool, createdAt: string}
     */
    public function toListItem(): array
    {
        return [
            'id' => $this->id,
            'database' => $this->database_name,
            'sql' => $this->sql,
            'statementCount' => $this->statement_count,
            'status' => $this->status,
            'error' => $this->error,
            'durationMs' => round($this->duration_ms, 1),
            'name' => $this->name,
            'saved' => $this->saved_at !== null,
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
