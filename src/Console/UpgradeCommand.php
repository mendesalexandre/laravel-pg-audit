<?php

namespace MendesAlexandre\PgAudit\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use MendesAlexandre\PgAudit\Console\Concerns\ExportsValues;
use MendesAlexandre\PgAudit\Database\AuditSchema;

class UpgradeCommand extends Command
{
    use ExportsValues;

    protected $signature = 'pg-audit:upgrade
        {--schema= : Schema do log}
        {--table= : Tabela de log padrão}
        {--connection= : Conexão do banco}
        {--migration : Gera uma migration em vez de alterar o banco agora (recomendado: migrate:fresh e novos ambientes ficam iguais)}';

    protected $description = 'Atualiza a função do trigger e as colunas/constraints de instalações antigas após atualizar o pacote';

    public function handle(Filesystem $files): int
    {
        $args = $this->exportArgs([
            'connection' => $this->option('connection'),
            'schema' => $this->option('schema'),
            'table' => $this->option('table'),
        ]);

        if ($this->option('migration')) {
            $path = $this->migrationPath('upgrade_pg_audit');
            $files->ensureDirectoryExists(dirname($path));
            $files->put($path, <<<PHP
            <?php

            use Illuminate\Database\Migrations\Migration;
            use MendesAlexandre\PgAudit\Database\AuditSchema;

            return new class extends Migration
            {
                public function up(): void
                {
                    AuditSchema::refreshFunction({$args});
                }

                public function down(): void
                {
                    // sem volta: a função nova é compatível com as colunas antigas
                }
            };

            PHP);

            $this->components->info("Migration criada: {$path}");

            return self::SUCCESS;
        }

        AuditSchema::refreshFunction($this->option('connection'), $this->option('schema'), $this->option('table'));
        $this->components->info('Função, colunas e constraints atualizadas. Os triggers existentes continuam valendo.');
        $this->components->warn('Isto alterou o banco direto. Use --migration para registrar a atualização numa migration.');

        return self::SUCCESS;
    }
}
