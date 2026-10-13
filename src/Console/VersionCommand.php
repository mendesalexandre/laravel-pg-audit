<?php

namespace MendesAlexandre\PgAudit\Console;

use Illuminate\Console\Command;
use MendesAlexandre\PgAudit\AuditManager;

class VersionCommand extends Command
{
    protected $signature = 'pg-audit:version
        {value? : Versão do esquema a gravar (ex.: SHA do deploy). Sem valor e sem --record, apenas mostra}
        {--record : Grava a versão configurada ou, se vazia, a última migration aplicada}
        {--connection= : Conexão do banco}';

    protected $description = 'Mostra ou grava a versão do esquema e do PostgreSQL usada nas linhas de auditoria';

    public function handle(AuditManager $audit): int
    {
        $connection = $this->option('connection');

        if ($audit->schemaState($connection) === null) {
            $this->components->error('Tabela de estado não encontrada. Rode `php artisan pg-audit:upgrade` (ou a migration de instalação).');

            return self::FAILURE;
        }

        if ($this->argument('value') !== null || $this->option('record')) {
            $version = $audit->recordSchemaVersion($this->argument('value'), $connection);
            $this->components->info('Versão do esquema gravada: '.($version ?? '(nenhuma migration encontrada)'));
        }

        $state = $audit->schemaState($connection);
        $this->table(['Versão do esquema', 'PostgreSQL', 'Atualizado em'], [[$state->version ?? '-', $state->pg_version, $state->updated_at]]);

        return self::SUCCESS;
    }
}
