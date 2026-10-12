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
            [$table, $opts] = is_int($key) ? [$value, []] : [$key, $value];
            $audit->enable($table, $opts['except'] ?? [], null, $opts['schema'] ?? null, $opts['connection'] ?? null);
            $this->components->info("Auditoria habilitada: {$table}");
        }

        return self::SUCCESS;
    }
}
