<?php

namespace MendesAlexandre\PgAudit\Console;

use Illuminate\Console\Command;
use MendesAlexandre\PgAudit\AuditManager;

class DisableCommand extends Command
{
    protected $signature = 'pg-audit:disable {tables* : Tabelas} {--connection= : Conexão do banco}';

    protected $description = 'Remove a auditoria (trigger) das tabelas informadas';

    public function handle(AuditManager $audit): int
    {
        foreach ($this->argument('tables') as $table) {
            $audit->disable($table, $this->option('connection'));
            $this->components->info("Auditoria desabilitada: {$table}");
        }

        return self::SUCCESS;
    }
}
