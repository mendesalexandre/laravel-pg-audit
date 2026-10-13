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
 * @property string $event  I|U|D|T|P|R (P/R = auditoria pausada/retomada)
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

    /**
     * O que mudou, campo a campo: [['column' => 'price', 'before' => 10, 'after' => 12], ...].
     * Insert: before = null; delete: after = null. Só as colunas gravadas no log (respeita `except`).
     */
    public function getDiffAttribute(): array
    {
        $old = $this->{Columns::get('old_values')} ?? [];
        $new = $this->{Columns::get('new_values')} ?? [];
        $rows = [];

        foreach (array_unique(array_merge(array_keys($new), array_keys($old))) as $column) {
            $rows[] = ['column' => $column, 'before' => $old[$column] ?? null, 'after' => $new[$column] ?? null];
        }

        return $rows;
    }

    /**
     * SQL equivalente com os valores reais, montado a partir de old_values/new_values (e não do texto capturado,
     * que traz $1, $2). É para LEITURA/relatório, não para executar: a coluna da chave é `pg-audit.primary_key` (padrão "id").
     */
    public function getReadableSqlAttribute(): ?string
    {
        $table = $this->{Columns::get('table')};
        $schema = $this->{Columns::get('schema')};
        $qualified = ($schema && $schema !== 'public' ? self::quoteIdentifier($schema).'.' : '').self::quoteIdentifier($table);
        $old = $this->{Columns::get('old_values')} ?? [];
        $new = $this->{Columns::get('new_values')} ?? [];
        $pk = (string) ($this->{Columns::get('row_id')} ?? '');
        $where = $pk === '' ? '' : ' where '.self::quoteIdentifier(config('pg-audit.primary_key', 'id')).' = '.self::quoteValue($pk);

        return match ($this->{Columns::get('event')}) {
            'I' => "insert into {$qualified} (".implode(', ', array_map([self::class, 'quoteIdentifier'], array_keys($new))).') values ('.implode(', ', array_map([self::class, 'quoteValue'], $new)).')',
            'U' => "update {$qualified} set ".implode(', ', array_map(fn ($c, $v) => self::quoteIdentifier($c).' = '.self::quoteValue($v), array_keys($new), $new)).$where,
            'D' => "delete from {$qualified}{$where}",
            'T' => "truncate {$qualified}",
            default => null,
        };
    }

    /** Resumo curto, ex.: `customers#1 U: lab_cpf: "000" -> "111"`. */
    public function getSummaryAttribute(): string
    {
        $id = $this->{Columns::get('row_id')};
        $head = $this->{Columns::get('table')}.($id !== null && $id !== '' ? "#{$id}" : '').' '.$this->{Columns::get('event')};
        $parts = array_map(fn ($c) => $c['column'].': '.json_encode($c['before'], JSON_UNESCAPED_UNICODE).' -> '.json_encode($c['after'], JSON_UNESCAPED_UNICODE), $this->diff);

        return $parts ? $head.': '.implode('; ', $parts) : $head;
    }

    protected static function quoteIdentifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    protected static function quoteValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            is_array($value) => "'".str_replace("'", "''", json_encode($value, JSON_UNESCAPED_UNICODE))."'",
            default => "'".str_replace("'", "''", (string) $value)."'",
        };
    }

    /**
     * Restaura a partir desta linha: DELETE reinsere a linha; UPDATE devolve os valores antigos.
     * $set preenche colunas que não foram gravadas (as de `except`, como senha). $dryRun só devolve o SQL.
     *
     * @return array{sql: string, skipped_columns: array, conflicts: array, done: bool}
     */
    public function restore(array $set = [], bool $dryRun = false, bool $overwrite = false): array
    {
        return app(\MendesAlexandre\PgAudit\Support\Restorer::class)->restoreRow($this, $set, $dryRun, $overwrite);
    }

    public function scopeDeletions(Builder $q): Builder
    {
        return $q->where(Columns::get('event'), 'D');
    }

    public function getActionNameAttribute(): string
    {
        return ['I' => 'created', 'U' => 'updated', 'D' => 'deleted', 'T' => 'truncated', 'P' => 'paused', 'R' => 'resumed'][$this->{Columns::get('event')}] ?? $this->{Columns::get('event')};
    }
}
