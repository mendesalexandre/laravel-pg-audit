<?php

namespace MendesAlexandre\PgAudit\Console;

use Illuminate\Console\Command;
use MendesAlexandre\PgAudit\Database\AuditSchema;

class UpgradeCommand extends Command
{
    protected $signature = 'pg-audit:upgrade {--schema= : Schema do log} {--table= : Tabela de log padrão} {--connection= : Conexão do banco}';

    protected $description = 'Atualiza a função do trigger e as constraints de instalações antigas (necessário para pausar a auditoria)';

    public function handle(): int
    {
        AuditSchema::refreshFunction($this->option('connection'), $this->option('schema'), $this->option('table'));
        $this->components->info('Função e constraints atualizadas. Os triggers existentes continuam valendo.');

        return self::SUCCESS;
    }
}
