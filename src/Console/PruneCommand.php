<?php

namespace MendesAlexandre\PgAudit\Console;

use Illuminate\Console\Command;
use MendesAlexandre\PgAudit\Models\AuditLog;

class PruneCommand extends Command
{
    protected $signature = 'pg-audit:prune {--days= : Dias de retenção (padrão: config retention_days)}';

    protected $description = 'Apaga logs de auditoria mais antigos que o período de retenção';

    public function handle(): int
    {
        $days = $this->option('days') ?? config('pg-audit.retention_days');

        if (! $days) {
            $this->components->error('Informe --days ou configure retention_days.');

            return self::FAILURE;
        }

        $deleted = AuditLog::where('created_at', '<', now()->subDays((int) $days))->delete();
        $this->components->info("{$deleted} registro(s) removido(s).");

        return self::SUCCESS;
    }
}
