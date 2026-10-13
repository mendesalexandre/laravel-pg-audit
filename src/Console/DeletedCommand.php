<?php

namespace MendesAlexandre\PgAudit\Console;

use Illuminate\Console\Command;
use MendesAlexandre\PgAudit\Models\AuditLog;
use MendesAlexandre\PgAudit\Support\Columns;
use MendesAlexandre\PgAudit\Support\LogSources;

class DeletedCommand extends Command
{
    protected $signature = 'pg-audit:deleted
        {--table= : Filtra pela tabela}
        {--user= : Filtra por quem apagou (user_id)}
        {--since= : A partir de (ex.: "2 hours ago", 2026-10-12)}
        {--log= : Só esta tabela de log (schema.tabela); padrão: todas as tabelas de log}
        {--limit=30 : Quantidade}
        {--connection= : Conexão do banco}';

    protected $description = 'Lista os DELETEs recentes (para achar o que apagaram sem querer e usar pg-audit:restore)';

    public function handle(): int
    {
        $connection = $this->option('connection');
        $logs = collect(LogSources::resolve($this->option('log'), $connection))
            ->flatMap(fn ($source) => LogSources::query($source, $connection)
                ->where(Columns::get('event'), 'D')
                ->when($this->option('table'), fn ($q, $t) => $q->where(Columns::get('table'), $t))
                ->when($this->option('user'), fn ($q, $u) => $q->where(Columns::get('user_id'), (string) $u))
                ->when($this->option('since'), fn ($q, $s) => $q->where(Columns::get('created_at'), '>=', now()->parse($s)))
                ->orderByDesc('id')->limit((int) $this->option('limit'))->get()
                ->each(fn ($l) => $l->setAttribute('_log', LogSources::key($source))))
            ->sortByDesc(fn ($l) => $l->{Columns::get('created_at')})
            ->take((int) $this->option('limit'))->values();

        if ($logs->isEmpty()) {
            $this->components->warn('Nenhum DELETE encontrado.');

            return self::SUCCESS;
        }

        $this->table(['Log', 'Id', 'Transação', 'Tabela', 'Chave', 'Quem', 'Quando', 'Dados'], $logs->map(fn ($l) => [
            $l->_log, $l->id, $l->{Columns::get('transaction_id')}, $l->{Columns::get('table')}, $l->{Columns::get('row_id')},
            $l->{Columns::get('user_id')} ?? '-', $l->{Columns::get('created_at')}?->format('Y-m-d H:i:s'),
            mb_strimwidth(json_encode($l->{Columns::get('old_values')}, JSON_UNESCAPED_UNICODE), 0, 60, '…'),
        ])->all());
        $this->line('Restaurar: php artisan pg-audit:restore <id> --log=<schema.tabela> [--dry-run]   |   cascata: pg-audit:restore --transaction=<transação>');

        return self::SUCCESS;
    }
}
