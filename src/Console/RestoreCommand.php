<?php

namespace MendesAlexandre\PgAudit\Console;

use Illuminate\Console\Command;
use MendesAlexandre\PgAudit\AuditManager;
use MendesAlexandre\PgAudit\Models\AuditLog;
use MendesAlexandre\PgAudit\Support\Columns;
use MendesAlexandre\PgAudit\Support\Restorer;

class RestoreCommand extends Command
{
    protected $signature = 'pg-audit:restore
        {id? : Id da linha do log (DELETE reinsere; UPDATE devolve os valores antigos)}
        {--transaction= : Restaura todos os DELETEs de uma transação (cascata), tudo-ou-nada}
        {--table= : Com --record: tabela; com --transaction: restringe a esta tabela}
        {--record= : Com --table: chave da linha; restaura o DELETE mais recente dela}
        {--set=* : Valor de coluna que não foi gravada (except), ex.: --set=password=hash ou --set=users.password=hash}
        {--user= : Id de quem está restaurando (a restauração também é auditada)}
        {--dry-run : Só mostra o SQL}
        {--force : Não pergunta}
        {--connection= : Conexão do banco}';

    protected $description = 'Restaura linhas apagadas (ou desfaz updates) a partir do log de auditoria';

    public function handle(AuditManager $audit, Restorer $restorer): int
    {
        $set = $this->parseSet($this->option('set'));
        $dry = (bool) $this->option('dry-run');
        $connection = $this->option('connection');

        try {
            $plan = $this->plan($restorer, $set, $connection);
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($plan as $row) {
            $this->line(($row['log_id'] ?? '').' '.$row['sql']);
            if ($row['skipped_columns']) {
                $this->components->warn('Colunas do log que não existem mais na tabela (ignoradas): '.implode(', ', $row['skipped_columns']));
            }
        }

        if ($dry) {
            $this->components->info('Simulação: nada foi gravado.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->components->confirm('Aplicar '.count($plan).' comando(s)?', false)) {
            return self::FAILURE;
        }

        $run = function () use ($restorer, $set, $connection) {
            if ($this->option('transaction')) {
                return $restorer->restoreTransaction($this->option('transaction'), $set, false, $this->option('table'), $connection);
            }

            return [['log_id' => null] + $this->findLog($connection)->restore($set)];
        };

        try {
            $user = $this->option('user');
            $result = $user !== null ? $audit->actingAs($user, $run, $connection) : $run();
        } catch (\Throwable $e) {
            $this->components->error('Falhou, nada foi restaurado: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(count($result).' linha(s) restaurada(s).');

        return self::SUCCESS;
    }

    protected function plan(Restorer $restorer, array $set, ?string $connection): array
    {
        if ($this->option('transaction')) {
            return $restorer->restoreTransaction($this->option('transaction'), $set, true, $this->option('table'), $connection);
        }

        return [['log_id' => $this->findLog($connection)->id] + $this->findLog($connection)->restore($set, true)];
    }

    protected function findLog(?string $connection): AuditLog
    {
        $q = AuditLog::inSchema(config('pg-audit.schema'), $connection);

        if ($this->argument('id')) {
            return $q->findOrFail($this->argument('id'));
        }
        if ($this->option('table') && $this->option('record') !== null) {
            return $q->where(Columns::get('table'), $this->option('table'))
                ->where(Columns::get('row_id'), (string) $this->option('record'))
                ->where(Columns::get('event'), 'D')
                ->orderByDesc('id')->firstOrFail();
        }

        throw new \InvalidArgumentException('Informe o id do log, --transaction ou --table + --record.');
    }

    protected function parseSet(array $pairs): array
    {
        $out = [];
        foreach ($pairs as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $out[trim($key)] = $value;
        }

        return $out;
    }
}
