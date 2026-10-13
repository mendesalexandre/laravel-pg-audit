<?php

namespace MendesAlexandre\PgAudit\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;
use MendesAlexandre\PgAudit\Models\AuditLog;
use Throwable;

/**
 * Restaura a partir do log: DELETE -> reinsere a linha (valores_antigos); UPDATE -> devolve os valores antigos.
 * Colunas que estão em `except` (senha, token...) nunca foram gravadas: informe em $set ou o banco usa o default/NULL.
 */
class Restorer
{
    /**
     * Restaura uma linha de log. Retorna ['sql' => ..., 'skipped_columns' => [...], 'done' => bool].
     * Com $dryRun não grava nada.
     *
     * UPDATE: só desfaz se a linha ainda estiver como o log a deixou (new_values). Se foi alterada depois, lança
     * LogicException (no dry-run devolve em 'conflicts'); $overwrite = true sobrescreve mesmo assim.
     */
    public function restoreRow(AuditLog $log, array $set = [], bool $dryRun = false, bool $overwrite = false): array
    {
        $event = $log->{Columns::get('event')};
        if (! in_array($event, ['D', 'U'], true)) {
            throw new LogicException($event === 'I'
                ? 'Insert não se restaura: apague a linha criada.'
                : "Evento {$event} não pode ser restaurado.");
        }

        $db = DB::connection($log->getConnectionName());
        $schema = $log->{Columns::get('schema')};
        $table = $log->{Columns::get('table')};
        $qualified = $schema.'.'.$table;
        $old = $log->{Columns::get('old_values')} ?? [];
        $set = $this->setFor($set, $table);

        $types = $this->columns($db, $schema, $table);
        $data = array_merge($old, $set);
        $skipped = array_values(array_diff(array_keys($data), array_keys($types)));
        $data = array_intersect_key($data, $types);
        $data = array_map(fn ($v, $c) => $this->encode($v, $types[$c]), $data, array_keys($data));
        $data = array_combine(array_keys(array_intersect_key(array_merge($old, $set), $types)), $data);

        $pk = $this->where($db, $log, $schema, $table);
        $sql = $this->sql($event, $qualified, $data, $pk);
        $conflicts = $event === 'U' ? $this->conflicts($db, $log, $qualified, $pk) : [];

        if ($dryRun) {
            return ['sql' => $sql, 'skipped_columns' => $skipped, 'conflicts' => $conflicts, 'done' => false];
        }

        if ($conflicts && ! $overwrite) {
            $what = implode('; ', array_map(fn ($c, $v) => "{$c}: atual ".json_encode($v['current'], JSON_UNESCAPED_UNICODE).', no log '.json_encode($v['logged'], JSON_UNESCAPED_UNICODE), array_keys($conflicts), $conflicts));

            throw new LogicException("A linha foi alterada depois deste update ({$what}). Use --overwrite para desfazer mesmo assim.");
        }

        if ($event === 'D') {
            $db->table($qualified)->insert($data);
        } elseif ($db->table($qualified)->where($pk)->update($data) !== 1) {
            throw new LogicException("Nenhuma linha de {$qualified} com a chave ".json_encode($pk).' (apagada depois?). Restaure o DELETE primeiro.');
        }

        return ['sql' => $sql, 'skipped_columns' => $skipped, 'conflicts' => $conflicts, 'done' => true];
    }

    /**
     * Restaura os DELETEs de uma transação, na ordem em que foram feitos (pais antes dos filhos na cascata),
     * tudo-ou-nada. Violações de chave estrangeira são repetidas em novas passadas até não haver progresso.
     *
     * @return array<int, array> resultado por linha de log
     */
    public function restoreTransaction(int|string $transactionId, array $set = [], bool $dryRun = false, ?string $table = null, ?string $connection = null, ?string $log = null, bool $overwrite = false): array
    {
        // a transação pode ter apagado em tabelas com logs diferentes (padrão, schema próprio, prefixo): junta todas
        $logs = collect(LogSources::resolve($log, $connection))
            ->flatMap(fn ($source) => LogSources::query($source, $connection)
                ->where(Columns::get('transaction_id'), $transactionId)
                ->where(Columns::get('event'), 'D')
                ->when($table, fn ($q) => $q->where(Columns::get('table'), $table))
                ->orderBy('id')->get())
            ->sortBy(fn ($l) => [$l->{Columns::get('created_at')}?->getTimestampMs(), $l->id])
            ->values();

        if ($logs->isEmpty()) {
            throw new LogicException("Nenhum DELETE encontrado na transação {$transactionId}.");
        }

        if ($dryRun) {
            return $logs->map(fn ($l) => ['log_id' => $l->id] + $this->restoreRow($l, $set, true, $overwrite))->all();
        }

        $db = DB::connection($logs->first()->getConnectionName());

        return $db->transaction(function () use ($logs, $set, $db, $overwrite) {
            $pending = $logs->all();
            $results = [];

            while ($pending) {
                $failed = [];
                $lastError = null;
                foreach ($pending as $log) {
                    try {
                        // savepoint por linha: uma violação de FK não aborta a transação inteira
                        $results[$log->id] = ['log_id' => $log->id] + $db->transaction(
                            fn () => $this->restoreRow($log, $set, false, $overwrite)
                        );
                    } catch (Throwable $e) {
                        if (! $this->isForeignKeyViolation($e)) {
                            throw $e;
                        }
                        $failed[] = $log;
                        $lastError = $e;
                    }
                }
                if (count($failed) === count($pending)) {
                    throw $lastError;       // sem progresso: a dependência não está no log
                }
                $pending = $failed;
            }
            ksort($results);

            return array_values($results);
        });
    }

    /** $set: 'coluna' => valor (todas as tabelas) e/ou 'tabela.coluna' => valor (só naquela tabela). */
    public function setFor(array $set, string $table): array
    {
        $out = [];
        foreach ($set as $key => $value) {
            if (! str_contains($key, '.')) {
                $out[$key] = $value;
            } elseif (str_starts_with($key, $table.'.')) {
                $out[substr($key, strlen($table) + 1)] = $value;
            }
        }

        return $out;
    }

    protected function isForeignKeyViolation(Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'SQLSTATE[23503]');
    }

    /** @return array<string, string> coluna => tipo interno (udt_name; arrays começam com "_") */
    protected function columns(Connection $db, string $schema, string $table): array
    {
        return array_column($db->select('SELECT column_name, udt_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ?', [$schema, $table]), 'udt_name', 'column_name');
    }

    /** Valor do log -> valor gravável. json/jsonb viram texto JSON; arrays do PostgreSQL (text[], int[]...) viram literal {a,b}. */
    protected function encode(mixed $value, string $udt): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        return str_starts_with($udt, '_') ? $this->pgArray($value) : json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    protected function pgArray(array $items): string
    {
        return '{'.implode(',', array_map(fn ($v) => match (true) {
            $v === null => 'NULL',
            is_array($v) => $this->pgArray($v),
            is_bool($v) => $v ? 'true' : 'false',
            default => '"'.addcslashes((string) $v, '"\\').'"',
        }, $items)).'}';
    }

    /**
     * Colunas do new_values do log cujo valor atual na tabela é outro (a linha foi alterada depois).
     * Compara via to_jsonb nos dois lados, então a formatação (datas, numeric) é a mesma do log.
     *
     * @return array<string, array{current: mixed, logged: mixed}>
     */
    protected function conflicts(Connection $db, AuditLog $log, string $qualified, array $pk): array
    {
        $logged = $log->{Columns::get('new_values')} ?? [];
        if (! $logged) {
            return [];
        }

        $g = $db->getQueryGrammar();
        $where = implode(' and ', array_map(fn ($c) => $g->wrap($c).' = ?', array_keys($pk)));
        $row = $db->selectOne('select to_jsonb(t) as j from '.$g->wrapTable($qualified).' t where '.$where, array_values($pk));
        if (! $row) {
            return [];      // a linha não existe: o update acusa isso com mensagem própria
        }

        $current = json_decode($row->j, true);
        $out = [];
        foreach ($logged as $column => $value) {
            if (! array_key_exists($column, $current) || $current[$column] != $value) {
                $out[$column] = ['current' => $current[$column] ?? null, 'logged' => $value];
            }
        }

        return $out;
    }

    /** Chave primária da linha: ['id' => '5'] (ou composta), na ordem em que o trigger montou o row_id. */
    protected function where(Connection $db, AuditLog $log, string $schema, string $table): array
    {
        $rowId = (string) $log->{Columns::get('row_id')};
        $cols = array_column($db->select(<<<'SQL'
            SELECT a.attname FROM pg_index i
              CROSS JOIN LATERAL unnest(i.indkey) WITH ORDINALITY AS k(attnum, ord)
              JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = k.attnum
             WHERE i.indrelid = (quote_ident(?) || '.' || quote_ident(?))::regclass AND i.indisprimary
             ORDER BY k.ord
        SQL, [$schema, $table]), 'attname');

        if (! $cols || $rowId === '') {
            throw new LogicException("{$schema}.{$table} não tem chave primária registrada no log; não dá para restaurar com segurança.");
        }

        return array_combine($cols, explode(',', $rowId, count($cols)));
    }

    protected function sql(string $event, string $qualified, array $data, array $where): string
    {
        $q = fn ($n) => '"'.str_replace('"', '""', $n).'"';
        $v = fn ($x) => $x === null ? 'NULL' : (is_bool($x) ? ($x ? 'true' : 'false') : (is_int($x) || is_float($x) ? (string) $x : "'".str_replace("'", "''", (string) $x)."'"));
        $table = implode('.', array_map($q, explode('.', $qualified, 2)));

        if ($event === 'D') {
            return "insert into {$table} (".implode(', ', array_map($q, array_keys($data))).') values ('.implode(', ', array_map($v, $data)).')';
        }

        return "update {$table} set ".implode(', ', array_map(fn ($c, $x) => $q($c).' = '.$v($x), array_keys($data), $data)).' where '
            .implode(' and ', array_map(fn ($c, $x) => $q($c).' = '.$v($x), array_keys($where), $where));
    }
}
