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
     */
    public function restoreRow(AuditLog $log, array $set = [], bool $dryRun = false): array
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

        $existing = $this->columns($db, $schema, $table);
        $data = array_merge($old, $set);
        $skipped = array_values(array_diff(array_keys($data), $existing));
        $data = array_intersect_key($data, array_flip($existing));
        $data = array_map(fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v, $data);

        $sql = $this->sql($event, $qualified, $data, $this->where($db, $log, $schema, $table));

        if ($dryRun) {
            return ['sql' => $sql, 'skipped_columns' => $skipped, 'done' => false];
        }

        if ($event === 'D') {
            $db->table($qualified)->insert($data);
        } else {
            $pk = $this->where($db, $log, $schema, $table);
            $db->table($qualified)->where($pk)->update($data);
        }

        return ['sql' => $sql, 'skipped_columns' => $skipped, 'done' => true];
    }

    /**
     * Restaura os DELETEs de uma transação, na ordem em que foram feitos (pais antes dos filhos na cascata),
     * tudo-ou-nada. Violações de chave estrangeira são repetidas em novas passadas até não haver progresso.
     *
     * @return array<int, array> resultado por linha de log
     */
    public function restoreTransaction(int|string $transactionId, array $set = [], bool $dryRun = false, ?string $table = null, ?string $connection = null): array
    {
        $logs = AuditLog::inSchema(config('pg-audit.schema'), $connection)
            ->where(Columns::get('transaction_id'), $transactionId)
            ->where(Columns::get('event'), 'D')
            ->when($table, fn ($q) => $q->where(Columns::get('table'), $table))
            ->orderBy('id')
            ->get();

        if ($logs->isEmpty()) {
            throw new LogicException("Nenhum DELETE encontrado na transação {$transactionId}.");
        }

        if ($dryRun) {
            return $logs->map(fn ($l) => ['log_id' => $l->id] + $this->restoreRow($l, $set, true))->all();
        }

        $db = DB::connection($logs->first()->getConnectionName());

        return $db->transaction(function () use ($logs, $set, $db) {
            $pending = $logs->all();
            $results = [];

            while ($pending) {
                $failed = [];
                $lastError = null;
                foreach ($pending as $log) {
                    try {
                        // savepoint por linha: uma violação de FK não aborta a transação inteira
                        $results[$log->id] = ['log_id' => $log->id] + $db->transaction(
                            fn () => $this->restoreRow($log, $set)
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

    protected function columns(Connection $db, string $schema, string $table): array
    {
        return array_column($db->select('SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ?', [$schema, $table]), 'column_name');
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
