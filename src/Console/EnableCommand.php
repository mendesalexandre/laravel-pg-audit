<?php

namespace MendesAlexandre\PgAudit\Console;

use Illuminate\Console\Command;
use MendesAlexandre\PgAudit\AuditManager;

class EnableCommand extends Command
{
    protected $signature = 'pg-audit:enable {tables* : Tabelas} {--except=* : Colunas ignoradas} {--no-truncate : Não auditar TRUNCATE}';

    protected $description = 'Habilita a auditoria (trigger) nas tabelas informadas';

    public function handle(AuditManager $audit): int
    {
        foreach ($this->argument('tables') as $table) {
            $audit->enable($table, $this->option('except'), $this->option('no-truncate') ? false : null);
            $this->components->info("Auditoria habilitada: {$table}");
        }

        return self::SUCCESS;
    }
}
