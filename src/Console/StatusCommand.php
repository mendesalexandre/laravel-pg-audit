<?php

namespace MendesAlexandre\PgAudit\Console;

use Illuminate\Console\Command;
use MendesAlexandre\PgAudit\AuditManager;
use MendesAlexandre\PgAudit\Database\AuditSchema;

class StatusCommand extends Command
{
    protected $signature = 'pg-audit:status {--connection= : Conexão do banco}';

    protected $description = 'Lista as tabelas com auditoria ativa';

    public function handle(AuditManager $audit): int
    {
        $connection = $this->option('connection');
        $rows = $audit->audited($connection);

        if ($state = $audit->schemaState($connection)) {
            $this->line('Versão do esquema: '.($state->version ?? '-').' | PostgreSQL: '.$state->pg_version.' | Histórico de DDL: '.(AuditSchema::ddlLogEnabled($connection) ? 'ligado' : 'desligado'));
        }

        // capture_query + prepares emulados: o texto da query leva os valores reais, inclusive de colunas em `except`.
        $capturing = array_filter($rows, fn ($r) => $r['captures_query']);
        if ($capturing && $audit->emulatesPrepares($connection)) {
            $this->components->warn('ATTR_EMULATE_PREPARES está ligado e capture_query grava o SQL com valores reais (colunas em `except` NÃO são mascaradas) em: '
                .implode(', ', array_map(fn ($r) => $r['table'].($r['except'] ? " (except: {$r['except']})" : ''), $capturing))
                .'. Restrinja `capture_query_tables` a tabelas sem dados sensíveis ou use PgAudit::withLiteralQueries() só nelas.');
        }

        if (! $rows) {
            $this->components->warn('Nenhuma tabela auditada.');

            return self::SUCCESS;
        }

        $this->table(['Tabela', 'Schema do log', 'Tabela de log', 'Grava query', 'Colunas ignoradas'], array_map(fn ($r) => [$r['table'], $r['audit_schema'], $r['log_table'], $r['captures_query'] ? 'sim' : '-', $r['except'] ?: '-'], $rows));

        return self::SUCCESS;
    }
}
