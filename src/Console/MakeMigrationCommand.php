<?php

namespace MendesAlexandre\PgAudit\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use MendesAlexandre\PgAudit\Console\Concerns\ExportsValues;

class MakeMigrationCommand extends Command
{
    use ExportsValues;

    protected $signature = 'pg-audit:make-migration
        {tables* : Tabelas existentes a auditar}
        {--except=* : Colunas ignoradas}
        {--schema= : Schema do log}
        {--prefix= : Prefixo da tabela de log própria (ex.: audit_)}
        {--connection= : Conexão do banco}';

    protected $description = 'Gera a migration que habilita a auditoria (trigger) em tabelas existentes';

    public function handle(Filesystem $files): int
    {
        $tables = $this->argument('tables');
        $connection = $this->option('connection');
        $schemaCall = $connection ? 'Schema::connection('.var_export($connection, true).')->table' : 'Schema::table';
        $opts = $this->exportArgs([
            'except' => $this->option('except'),
            'schema' => $this->option('schema'),
            'prefix' => $this->option('prefix'),
        ]);

        $up = $down = '';
        foreach ($tables as $table) {
            $t = var_export($table, true);
            $up .= "        {$schemaCall}({$t}, fn (Blueprint \$t) => \$t->audit({$opts}));\n";
            $down .= "        {$schemaCall}({$t}, fn (Blueprint \$t) => \$t->dropAudit());\n";
        }

        $name = 'enable_audit_on_'.implode('_', array_map(fn ($t) => str_replace('.', '_', $t), $tables));
        $path = $this->migrationPath($name);
        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, <<<PHP
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
        {$up}    }

            public function down(): void
            {
        {$down}    }
        };

        PHP);

        $this->components->info("Migration criada: {$path}");

        return self::SUCCESS;
    }
}
