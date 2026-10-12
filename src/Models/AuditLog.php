<?php

namespace MendesAlexandre\PgAudit\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $table_name
 * @property string|null $row_pk
 * @property string $action  I|U|D|T
 * @property array|null $old_values
 * @property array|null $new_values
 * @property string|null $user_id
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    public function getConnectionName(): ?string
    {
        return config('pg-audit.connection');
    }

    public function getTable(): string
    {
        return config('pg-audit.schema').'.'.config('pg-audit.table');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('pg-audit.user_model'), 'user_id');
    }

    public function scopeForTable(Builder $q, string $table): Builder
    {
        return $q->where('table_name', $table);
    }

    public function scopeForRecord(Builder $q, string $table, string|int $pk): Builder
    {
        return $q->where('table_name', $table)->where('row_pk', (string) $pk);
    }

    public function scopeAction(Builder $q, string ...$actions): Builder
    {
        return $q->whereIn('action', $actions);
    }

    public function getActionNameAttribute(): string
    {
        return ['I' => 'created', 'U' => 'updated', 'D' => 'deleted', 'T' => 'truncated'][$this->action] ?? $this->action;
    }
}
