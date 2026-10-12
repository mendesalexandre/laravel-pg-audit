<?php

namespace MendesAlexandre\PgAudit\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use MendesAlexandre\PgAudit\Console\Concerns\ExportsValues;

class InstallCommand extends Command
{
    use ExportsValues;

    protected $signature = 'pg-audit:install
        {--schema= : Schema do log (padrão: config)}
        {--table= : Tabela de log padrão (padrão: config)}
        {--connection= : Conexão do banco}';

    protected $description = 'Gera a migration que instala o schema de auditoria (schema, tabela de log e função)';

    public function handle(Filesystem $files): int
    {
        $schema = $this->option('schema') ?: config('pg-audit.schema');
        $args = $this->exportArgs([
            'connection' => $this->option('connection'),
            'schema' => $this->option('schema'),
            'table' => $this->option('table'),
        ]);

        $path = database_path('migrations/'.date('Y_m_d_His').'_create_pg_audit_schema_'.$schema.'.php');
        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, <<<PHP
        <?php

        use Illuminate\Database\Migrations\Migration;
        use MendesAlexandre\PgAudit\Database\AuditSchema;

        return new class extends Migration
        {
            public function up(): void
            {
                AuditSchema::up({$args});
            }

            public function down(): void
            {
                AuditSchema::down({$this->exportArgs(['connection' => $this->option('connection'), 'schema' => $this->option('schema')])});
            }
        };

        PHP);

        $this->components->info("Migration criada: {$path}");

        return self::SUCCESS;
    }
}
