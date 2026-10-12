<?php

namespace MendesAlexandre\PgAudit\Concerns;

use Illuminate\Database\Eloquent\Relations\HasMany;
use MendesAlexandre\PgAudit\Models\AuditLog;

/**
 * Adicione ao model para ter $model->auditLogs (histórico da linha).
 * Não habilita o trigger: use $table->audit() na migration ou pg-audit:enable.
 */
trait HasAuditLog
{
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'row_pk', $this->getKeyName())
            ->where('table_name', $this->getTable())
            ->latest('id');
    }
}
