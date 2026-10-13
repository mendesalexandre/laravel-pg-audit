<?php

namespace MendesAlexandre\PgAudit\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use MendesAlexandre\PgAudit\Console\Concerns\ExportsValues;
use MendesAlexandre\PgAudit\Support\Columns;

class RenameColumnsCommand extends Command
{
    use ExportsValues;

    protected $signature = 'pg-audit:rename-columns
        {--from=en : Preset atual das colunas no banco (en|pt_BR)}
        {--to= : Preset de destino (padrão: nomes da config atual, incluindo overrides)}
        {--from-columns=* : Overrides do estado atual, ex.: row_id=registro_id}
        {--schema= : Schema do log}
        {--table= : Tabela de log padrão}
        {--connection= : Conexão do banco}';

    protected $description = 'Gera a migration que renomeia as colunas das tabelas de log (ex.: created_at -> criado_em) e recria a função do trigger';

    public function handle(Filesystem $files): int
    {
        $from = Columns::for($this->option('from'), $this->parseOverrides($this->option('from-columns')));
        $to = $this->option('to') ? Columns::for($this->option('to')) : Columns::all();

        if ($from === $to) {
            $this->components->warn('Origem e destino são iguais; nada a renomear.');

            return self::FAILURE;
        }

        $opts = $this->exportArgs([
            'connection' => $this->option('connection'),
            'schema' => $this->option('schema'),
            'table' => $this->option('table'),
        ]);
        $opts = $opts ? ', '.$opts : '';
        $fromCode = var_export($from, true);
        $toCode = var_export($to, true);

        $path = $this->migrationPath('rename_pg_audit_columns');
        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, <<<PHP
        <?php

        use Illuminate\Database\Migrations\Migration;
        use MendesAlexandre\PgAudit\Database\AuditSchema;

        return new class extends Migration
        {
            private array \$from = {$fromCode};

            private array \$to = {$toCode};

            public function up(): void
            {
                AuditSchema::renameColumns(\$this->from, \$this->to{$opts});
            }

            public function down(): void
            {
                AuditSchema::renameColumns(\$this->to, \$this->from{$opts});
            }
        };

        PHP);

        $this->components->info("Migration criada: {$path}");
        $this->line('Lembre-se de manter pg-audit.locale/columns na config igual ao destino.');

        return self::SUCCESS;
    }

    protected function parseOverrides(array $pairs): array
    {
        $out = [];
        foreach ($pairs as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $out[$k] = $v;
        }

        return $out;
    }
}
