<?php

namespace MendesAlexandre\PgAudit\Console;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use MendesAlexandre\PgAudit\Database\AuditSchema;

class DdlLogCommand extends Command
{
    protected $signature = 'pg-audit:ddl-log
        {action=status : enable | disable | status | sql}
        {--schema= : Schema do log (padrão: config)}
        {--connection= : Conexão do banco}';

    protected $description = 'Histórico de DDL (ALTER/CREATE/DROP) por event trigger: liga, desliga, mostra o estado ou imprime o SQL para um superusuário';

    public function handle(): int
    {
        $connection = $this->option('connection');

        switch ($this->argument('action')) {
            case 'enable':
                try {
                    AuditSchema::enableDdlLog($connection, $this->option('schema'));
                } catch (QueryException $e) {
                    if ($e->getCode() === '42501') {
                        $this->components->error('Criar event trigger exige superusuário. Peça ao DBA para rodar (`pg-audit:ddl-log sql` imprime o SQL):');
                        $this->line(implode(";\n", AuditSchema::ddlLogStatements($this->option('schema'))).';');

                        return self::FAILURE;
                    }
                    throw $e;
                }
                $this->components->info('Histórico de DDL ligado.');

                return self::SUCCESS;
            case 'disable':
                AuditSchema::disableDdlLog($connection);
                $this->components->info('Histórico de DDL desligado.');

                return self::SUCCESS;
            case 'sql':
                $this->line(implode(";\n", AuditSchema::ddlLogStatements($this->option('schema'))).';');

                return self::SUCCESS;
            default:
                $this->components->info('Histórico de DDL: '.(AuditSchema::ddlLogEnabled($connection) ? 'ligado' : 'desligado'));

                return self::SUCCESS;
        }
    }
}
