<?php

namespace MendesAlexandre\PgAudit\Console;

use Illuminate\Console\Command;
use MendesAlexandre\PgAudit\AuditManager;

class SyncCommand extends Command
{
    protected $signature = 'pg-audit:sync';

    protected $description = 'Habilita a auditoria nas tabelas listadas em config/pg-audit.php (chave "tables")';

    public function handle(AuditManager $audit): int
    {
        foreach (config('pg-audit.tables', []) as $key => $value) {
            [$table, $except] = is_int($key) ? [$value, []] : [$key, $value['except'] ?? []];
            $audit->enable($table, $except);
            $this->components->info("Auditoria habilitada: {$table}");
        }

        return self::SUCCESS;
    }
}
