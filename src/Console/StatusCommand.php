<?php

namespace MendesAlexandre\PgAudit\Console;

use Illuminate\Console\Command;
use MendesAlexandre\PgAudit\AuditManager;

class StatusCommand extends Command
{
    protected $signature = 'pg-audit:status';

    protected $description = 'Lista as tabelas com auditoria ativa';

    public function handle(AuditManager $audit): int
    {
        $rows = $audit->audited();

        if (! $rows) {
            $this->components->warn('Nenhuma tabela auditada.');

            return self::SUCCESS;
        }

        $this->table(['Tabela', 'Colunas ignoradas'], array_map(fn ($r) => [$r['table'], $r['except'] ?: '-'], $rows));

        return self::SUCCESS;
    }
}
