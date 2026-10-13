<?php

namespace MendesAlexandre\PgAudit\Concerns;

use Illuminate\Database\Eloquent\Builder;
use MendesAlexandre\PgAudit\AuditManager;

/**
 * Adicione a models SEM dados sensíveis para que o SQL gravado por `capture_query` traga os valores reais
 * (`update "customers" set "cpf" = '123' where "id" = 1`) em vez de `$1, $2`, sem mudar o PDO do app:
 *
 *   class Customer extends Model { use HasAuditLog, WithLiteralQueries; }
 *
 * Vale para create/save/update/delete do próprio model. NÃO cobre `Customer::query()->update([...])` em massa nem
 * `DB::table()`; para esses, use PgAudit::withLiteralQueries(fn () => ...).
 *
 * Trava de segurança: se a gravação inclui uma coluna que está em `global_except` (password, remember_token) ou no
 * `$auditExcept` do model, aquela gravação continua parametrizada (os valores não vão para o texto da query).
 * Opcional: `protected array $literalQueryExcept = ['token'];` para colunas extras que nunca devem ir em texto.
 */
trait WithLiteralQueries
{
    protected function performInsert(Builder $query)
    {
        return $this->literalQueries($this->getAttributes(), fn () => parent::performInsert($query));
    }

    protected function performUpdate(Builder $query)
    {
        return $this->literalQueries($this->getDirty(), fn () => parent::performUpdate($query));
    }

    protected function performDeleteOnModel()
    {
        return $this->literalQueries([], fn () => parent::performDeleteOnModel());
    }

    private function literalQueries(array $written, \Closure $write): mixed
    {
        $sensitive = array_merge(
            config('pg-audit.global_except', []),
            $this->auditExcept ?? [],
            $this->literalQueryExcept ?? [],
        );

        if (array_intersect(array_keys($written), $sensitive)) {
            return $write();            // mantém prepares reais: nada sensível vai em texto
        }

        return app(AuditManager::class)->withLiteralQueries($write, $this->getConnectionName());
    }
}
