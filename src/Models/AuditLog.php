<?php

namespace MendesAlexandre\PgAudit\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MendesAlexandre\PgAudit\Support\Columns;

/**
 * Nomes das colunas vêm de config (pg-audit.locale / pg-audit.columns). Padrão 'en':
 *
 * @property int $id
 * @property string $auditable_table
 * @property string|null $auditable_id
 * @property string $event  I|U|D|T
 * @property array|null $old_values
 * @property array|null $new_values
 * @property string|null $user_id
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function getCasts(): array
    {
        return [
            Columns::get('old_values') => 'array',
            Columns::get('new_values') => 'array',
            Columns::get('created_at') => 'datetime',
        ];
    }

    public function getConnectionName(): ?string
    {
        return $this->connection ?? config('pg-audit.connection');
    }

    public function getTable(): string
    {
        return $this->table ?? config('pg-audit.schema').'.'.config('pg-audit.table');
    }

    /** Query em outro schema/conexão: AuditLog::inSchema('audit_financeiro', 'tenant')->get() */
    public static function inSchema(string $schema, ?string $connection = null, ?string $table = null): Builder
    {
        $model = new static;
        $model->setConnection($connection ?? config('pg-audit.connection'));
        $model->setTable($schema.'.'.($table ?? config('pg-audit.table')));

        return $model->newQuery();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('pg-audit.user_model'), Columns::get('user_id'));
    }

    public function scopeForTable(Builder $q, string $table): Builder
    {
        return $q->where(Columns::get('table'), $table);
    }

    public function scopeForRecord(Builder $q, string $table, string|int $pk): Builder
    {
        return $q->where(Columns::get('table'), $table)->where(Columns::get('row_id'), (string) $pk);
    }

    public function scopeAction(Builder $q, string ...$actions): Builder
    {
        return $q->whereIn(Columns::get('event'), $actions);
    }

    public function getActionNameAttribute(): string
    {
        return ['I' => 'created', 'U' => 'updated', 'D' => 'deleted', 'T' => 'truncated'][$this->{Columns::get('event')}] ?? $this->{Columns::get('event')};
    }
}
