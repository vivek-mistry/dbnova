<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A change made to the managed MySQL server through DBNova.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $mysql_username
 * @property string $action
 * @property string|null $database_name
 * @property string|null $table_name
 * @property array<string, mixed>|null $details
 * @property bool $succeeded
 * @property string|null $error
 * @property string|null $ip_address
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'mysql_username', 'action', 'database_name', 'table_name', 'details', 'succeeded', 'error', 'ip_address'])]
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    /**
     * Audit entries are never updated.
     */
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
            'succeeded' => 'boolean',
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
}
