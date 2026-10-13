<?php

namespace MendesAlexandre\PgAudit\Console;

use Illuminate\Console\Command;
use MendesAlexandre\PgAudit\AuditManager;

class EnableCommand extends Command
{
    protected $signature = 'pg-audit:enable {tables* : Tabelas ou classes de model (App\\Models\\Order)} {--except=* : Colunas ignoradas} {--no-truncate : Não auditar TRUNCATE} {--schema= : Schema do log (padrão: config)} {--connection= : Conexão do banco} {--capture-query : Grava o SQL (current_query) nesta tabela} {--prefix= : Prefixo da tabela de log própria (ex.: audit_)}';

    protected $description = 'Habilita a auditoria (trigger) nas tabelas informadas';

    public function handle(AuditManager $audit): int
    {
        foreach ($this->argument('tables') as $table) {
            if (class_exists($table)) {
                $audit->enableModel($table, $this->option('except'), $this->option('no-truncate') ? false : null);
                $this->components->info("Auditoria habilitada (model): {$table}");

                continue;
            }

            $audit->enable($table, $this->option('except'), $this->option('no-truncate') ? false : null, $this->option('schema'), $this->option('connection'), $this->option('prefix'), $this->option('capture-query') ?: null);
            $this->components->info("Auditoria habilitada: {$table}");
        }

        return self::SUCCESS;
    }
}
