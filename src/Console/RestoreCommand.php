<?php

namespace MendesAlexandre\PgAudit\Console;

use Illuminate\Console\Command;
use MendesAlexandre\PgAudit\AuditManager;
use MendesAlexandre\PgAudit\Models\AuditLog;
use MendesAlexandre\PgAudit\Support\Columns;
use MendesAlexandre\PgAudit\Support\LogSources;
use MendesAlexandre\PgAudit\Support\Restorer;

class RestoreCommand extends Command
{
    protected $signature = 'pg-audit:restore
        {id? : Id da linha do log (DELETE reinsere; UPDATE devolve os valores antigos)}
        {--transaction= : Restaura todos os DELETEs de uma transação (cascata), tudo-ou-nada}
        {--table= : Com --record: tabela; com --transaction: restringe a esta tabela}
        {--log= : Tabela de log (schema.tabela) do id; padrão: procura em todas}
        {--overwrite : Desfaz o update mesmo que a linha tenha sido alterada depois}
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
            foreach ($row['conflicts'] ?? [] as $column => $c) {
                $this->components->warn("Alterada depois do log: {$column} (atual ".json_encode($c['current'], JSON_UNESCAPED_UNICODE).', log '.json_encode($c['logged'], JSON_UNESCAPED_UNICODE).'). Use --overwrite para desfazer mesmo assim.');
            }
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
                return $restorer->restoreTransaction($this->option('transaction'), $set, false, $this->option('table'), $connection, $this->option('log'), (bool) $this->option('overwrite'));
            }

            return [['log_id' => null] + $this->findLog($connection)->restore($set, false, (bool) $this->option('overwrite'))];
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
            return $restorer->restoreTransaction($this->option('transaction'), $set, true, $this->option('table'), $connection, $this->option('log'), (bool) $this->option('overwrite'));
        }

        return [['log_id' => $this->findLog($connection)->id] + $this->findLog($connection)->restore($set, true)];
    }

    protected function findLog(?string $connection): AuditLog
    {
        $sources = LogSources::resolve($this->option('log'), $connection);

        if ($this->argument('id')) {
            $found = collect($sources)->map(fn ($s) => LogSources::query($s, $connection)->find($this->argument('id')))->filter()->values();

            return match ($found->count()) {
                0 => throw new \InvalidArgumentException("Log {$this->argument('id')} não encontrado.".($this->option('log') ? '' : ' (procurei em todas as tabelas de log)')),
                1 => $found->first(),
                default => throw new \InvalidArgumentException("O id {$this->argument('id')} existe em mais de uma tabela de log; informe --log=schema.tabela."),
            };
        }

        if ($this->option('table') && $this->option('record') !== null) {
            $latest = collect($sources)->flatMap(fn ($s) => LogSources::query($s, $connection)
                ->where(Columns::get('table'), $this->option('table'))
                ->where(Columns::get('row_id'), (string) $this->option('record'))
                ->where(Columns::get('event'), 'D')
                ->orderByDesc('id')->limit(1)->get())
                ->sortByDesc(fn ($l) => $l->{Columns::get('created_at')})->first();

            return $latest ?? throw new \InvalidArgumentException('Nenhum DELETE encontrado para essa tabela/chave.');
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
