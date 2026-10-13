<?php

namespace MendesAlexandre\PgAudit\Console;

use Illuminate\Console\Command;
use MendesAlexandre\PgAudit\AuditManager;
use MendesAlexandre\PgAudit\Support\Columns;
use MendesAlexandre\PgAudit\Support\LogSources;

class PruneCommand extends Command
{
    protected $signature = 'pg-audit:prune
        {--days= : Dias de retenção (padrão: config retention_days)}
        {--log= : Só esta tabela de log (schema.tabela); padrão: todas as tabelas de log}
        {--connection= : Conexão do banco (use uma com role que tenha DELETE no log)}';

    protected $description = 'Apaga logs de auditoria mais antigos que o período de retenção (em todas as tabelas de log)';

    public function handle(AuditManager $audit): int
    {
        $days = $this->option('days') ?? config('pg-audit.retention_days');

        if (! $days) {
            $this->components->error('Informe --days ou configure retention_days.');

            return self::FAILURE;
        }

        $connection = $this->option('connection');
        $before = now()->subDays((int) $days);
        $total = 0;

        try {
            foreach (LogSources::resolve($this->option('log'), $connection) as $source) {
                $deleted = $audit->withPurge(
                    fn () => LogSources::query($source, $connection)->where(Columns::get('created_at'), '<', $before)->delete(),
                    $connection
                );
                $total += $deleted;
                $this->components->twoColumnDetail(LogSources::key($source), "{$deleted} removido(s)");
            }
        } catch (\Throwable $e) {
            $this->components->error('Falhou: '.$e->getMessage());
            $this->line('Se o role do app não tem DELETE no log (pg-audit:grant), rode com --connection=<conexão com role dono>.');

            return self::FAILURE;
        }

        $this->components->info("{$total} registro(s) removido(s) no total.");

        return self::SUCCESS;
    }
}
