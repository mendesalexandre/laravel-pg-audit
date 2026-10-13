<?php

namespace MendesAlexandre\PgAudit\Concerns;

use Illuminate\Database\Eloquent\Relations\HasMany;
use MendesAlexandre\PgAudit\Models\AuditLog;
use MendesAlexandre\PgAudit\Support\Columns;

/**
 * Adicione ao model para ter $model->auditLogs (histórico da linha).
 * Não habilita o trigger: use $table->audit() na migration ou pg-audit:enable.
 *
 * Opcional no model, se o trigger usa schema/conexão/prefixo próprios:
 *   protected string $auditSchema = 'audit_vendas';
 *   protected ?string $auditConnection = 'tenant';
 *   protected ?string $auditPrefix = 'audit_';
 *   protected array $auditExcept = ['updated_at', 'views'];   // colunas fora do log
 *
 * Com isso: PgAudit::enableModel(Order::class) ou `php artisan pg-audit:enable "App\\Models\\Order"`.
 */
trait HasAuditLog
{
    public function auditLogs(): HasMany
    {
        $prefix = $this->auditPrefix ?? config('pg-audit.table_prefix');
        $log = new AuditLog;
        $log->setConnection($this->auditConnection ?? config('pg-audit.connection'));
        $log->setTable(($this->auditSchema ?? config('pg-audit.schema')).'.'.($prefix ? $prefix.$this->getTable() : config('pg-audit.table')));

        return (new HasMany($log->newQuery(), $this, Columns::get('row_id'), $this->getKeyName()))
            ->where(Columns::get('table'), $this->getTable())
            ->latest(Columns::get('id'));
    }

    /** Configuração do trigger declarada no model (usada por PgAudit::enableModel / pg-audit:enable). */
    public function auditConfig(): array
    {
        return [
            'table' => $this->getTable(),
            'except' => $this->auditExcept ?? [],
            'schema' => $this->auditSchema ?? null,
            'prefix' => $this->auditPrefix ?? null,
            'connection' => $this->auditConnection ?? $this->getConnectionName(),
        ];
    }
}
