<?php

namespace MendesAlexandre\PgAudit\Console;

use Illuminate\Console\Command;
use MendesAlexandre\PgAudit\AuditManager;

class StatusCommand extends Command
{
    protected $signature = 'pg-audit:status {--connection= : Conexão do banco}';

    protected $description = 'Lista as tabelas com auditoria ativa';

    public function handle(AuditManager $audit): int
    {
        $rows = $audit->audited($this->option('connection'));

        if (! $rows) {
            $this->components->warn('Nenhuma tabela auditada.');

            return self::SUCCESS;
        }

        $this->table(['Tabela', 'Schema do log', 'Tabela de log', 'Colunas ignoradas'], array_map(fn ($r) => [$r['table'], $r['audit_schema'], $r['log_table'], $r['except'] ?: '-'], $rows));

        return self::SUCCESS;
    }
}
