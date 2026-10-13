<?php

namespace MendesAlexandre\PgAudit\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use MendesAlexandre\PgAudit\Models\AuditLog;

/**
 * Descobre todas as tabelas de log do banco (padrão de cada schema e as por prefixo, ex.: audit_vendas.audit_orders).
 * Reconhece pelas colunas de evento, tabela, chave da linha, valores antigos/novos e transação.
 */
class LogSources
{
    /** @return array<int, array{schema: string, table: string}> */
    public static function all(?string $connection = null): array
    {
        $keys = array_values(array_unique(array_map(fn ($k) => Columns::get($k), ['event', 'table', 'row_id', 'old_values', 'new_values', 'transaction_id'])));
        $marks = implode(', ', array_fill(0, count($keys), '?'));

        $rows = DB::connection($connection ?? config('pg-audit.connection'))->select(
            "SELECT table_schema, table_name FROM information_schema.columns
              WHERE table_schema NOT IN ('information_schema', 'pg_catalog') AND column_name IN ({$marks})
              GROUP BY table_schema, table_name HAVING count(*) = ?
              ORDER BY table_schema, table_name",
            [...$keys, count($keys)]
        );

        return array_map(fn ($r) => ['schema' => $r->table_schema, 'table' => $r->table_name], $rows);
    }

    /** "schema.tabela" -> fonte. */
    public static function parse(string $log): array
    {
        $parts = explode('.', $log, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidArgumentException("Informe a tabela de log como schema.tabela (recebido: {$log}).");
        }

        return ['schema' => $parts[0], 'table' => $parts[1]];
    }

    /** Fontes a consultar: a indicada em $log ("schema.tabela") ou todas. */
    public static function resolve(?string $log, ?string $connection = null): array
    {
        return $log ? [self::parse($log)] : self::all($connection);
    }

    public static function query(array $source, ?string $connection = null): Builder
    {
        return AuditLog::inSchema($source['schema'], $connection, $source['table']);
    }

    public static function key(array $source): string
    {
        return $source['schema'].'.'.$source['table'];
    }
}
