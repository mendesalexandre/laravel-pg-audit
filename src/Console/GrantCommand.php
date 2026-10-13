<?php

namespace MendesAlexandre\PgAudit\Console;

use Illuminate\Console\Command;
use MendesAlexandre\PgAudit\Database\AuditSchema;

class GrantCommand extends Command
{
    protected $signature = 'pg-audit:grant
        {role : Role do banco usado pelo app (diferente do dono do schema de auditoria)}
        {--read : Também dá SELECT nos logs (tela de histórico, pg-audit:deleted/restore)}
        {--schema= : Schema do log (padrão: config)}
        {--connection= : Conexão do banco (use a do dono do schema)}';

    protected $description = 'Dá ao role do app só o necessário para auditar: INSERT nos logs, sem UPDATE/DELETE/TRUNCATE';

    public function handle(): int
    {
        $warnings = AuditSchema::grantApp($this->argument('role'), (bool) $this->option('read'), $this->option('connection'), $this->option('schema'));

        foreach ($warnings as $warning) {
            $this->components->warn($warning);
        }

        $this->components->info("Permissões aplicadas a {$this->argument('role')}: INSERT".($this->option('read') ? ' + SELECT' : '').' nos logs.');
        $this->line('Apagar por retenção (pg-audit:prune) exige o role dono: use --connection=<conexão do dono>.');

        return self::SUCCESS;
    }
}
