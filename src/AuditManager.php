<?php

namespace MendesAlexandre\PgAudit;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use MendesAlexandre\PgAudit\Database\AuditSchema;

class AuditManager
{
    protected const CONTEXT_KEYS = ['user_id', 'ip_address', 'user_agent', 'url'];

    public function connection(?string $name = null): Connection
    {
        return DB::connection($name ?? config('pg-audit.connection'));
    }

    /**
     * SQL (array) para habilitar auditoria numa tabela.
     * $prefix (ou config "table_prefix") cria uma tabela de log própria: prefixo + nome da tabela (ex.: audit_orders).
     */
    public function enableStatements(string $quotedTable, array $except = [], ?bool $truncate = null, ?string $schema = null, ?string $prefix = null): array
    {
        $schemaName = AuditSchema::ident($schema ?? config('pg-audit.schema'));
        $schema = AuditSchema::q($schemaName);
        $columns = $this->columnList(array_merge(config('pg-audit.global_except', []), $except));
        $args = "'".$columns."'";
        $sql = [];

        $logTable = $this->logTableName($quotedTable, $prefix);
        if ($logTable !== null) {
            $sql = AuditSchema::logTableStatements($schema, AuditSchema::q($logTable));
            $args .= ", '".$logTable."'";
        }

        $row = AuditSchema::q(AuditSchema::TRIGGER_ROW);
        $stm = AuditSchema::q(AuditSchema::TRIGGER_TRUNCATE);

        array_push($sql,
            "DROP TRIGGER IF EXISTS {$row} ON {$quotedTable}",
            "CREATE TRIGGER {$row} AFTER INSERT OR UPDATE OR DELETE ON {$quotedTable} FOR EACH ROW EXECUTE FUNCTION {$schema}.log_changes({$args})",
            "DROP TRIGGER IF EXISTS {$stm} ON {$quotedTable}",
        );

        if ($truncate ?? config('pg-audit.truncate', true)) {
            $sql[] = "CREATE TRIGGER {$stm} AFTER TRUNCATE ON {$quotedTable} FOR EACH STATEMENT EXECUTE FUNCTION {$schema}.log_changes({$args})";
        }

        return $sql;
    }

    /** Nome da tabela de log própria (prefixo + tabela) ou null para usar a tabela padrão. */
    protected function logTableName(string $quotedTable, ?string $prefix): ?string
    {
        $prefix ??= config('pg-audit.table_prefix');
        if ($prefix === null || $prefix === '') {
            return null;
        }

        $base = trim(Arr::last(explode('.', $quotedTable)), '"');

        return AuditSchema::ident($prefix.$base);
    }

    public function disableStatements(string $quotedTable): array
    {
        return [
            'DROP TRIGGER IF EXISTS '.AuditSchema::q(AuditSchema::TRIGGER_ROW)." ON {$quotedTable}",
            'DROP TRIGGER IF EXISTS '.AuditSchema::q(AuditSchema::TRIGGER_TRUNCATE)." ON {$quotedTable}",
        ];
    }

    public function enable(string $table, array $except = [], ?bool $truncate = null, ?string $schema = null, ?string $connection = null, ?string $prefix = null): void
    {
        $db = $this->connection($connection);
        foreach ($this->enableStatements($this->quoteTable($db, $table), $except, $truncate, $schema, $prefix) as $sql) {
            $db->unprepared($sql);
        }
    }

    public function disable(string $table, ?string $connection = null): void
    {
        $db = $this->connection($connection);
        foreach ($this->disableStatements($this->quoteTable($db, $table)) as $sql) {
            $db->unprepared($sql);
        }
    }

    /** Tabelas com auditoria ativa: [['table' => 'public.users', 'except' => 'a,b'], ...] */
    public function audited(?string $connection = null): array
    {
        $rows = $this->connection($connection)->select(<<<'SQL'
            SELECT n.nspname AS schema, c.relname AS table, pg_get_triggerdef(t.oid) AS def
              FROM pg_trigger t
              JOIN pg_class c ON c.oid = t.tgrelid
              JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE NOT t.tgisinternal AND t.tgname = ?
             ORDER BY n.nspname, c.relname
        SQL, [AuditSchema::TRIGGER_ROW]);

        return array_map(function ($r) {
            preg_match("/EXECUTE FUNCTION (?:(.+)\\.)?log_changes\\('([^']*)'(?:, '([^']*)')?\\)/", $r->def, $m);

            return ['table' => "{$r->schema}.{$r->table}", 'audit_schema' => trim($m[1] ?? '', '"') ?: '(search_path)', 'log_table' => $m[3] ?? config('pg-audit.table'), 'except' => $m[2] ?? ''];
        }, $rows);
    }

    /** Define contexto da sessão do banco (lido pelo trigger). */
    public function setContext(array $context, ?string $connection = null): void
    {
        $db = $this->connection($connection);
        foreach (self::CONTEXT_KEYS as $key) {
            if (array_key_exists($key, $context)) {
                $db->select('select set_config(?, ?, false)', ["audit.{$key}", (string) ($context[$key] ?? '')]);
            }
        }
    }

    public function clearContext(?string $connection = null): void
    {
        $this->setContext(array_fill_keys(self::CONTEXT_KEYS, ''), $connection);
    }

    /** Executa o callback atribuindo as alterações a $userId e restaura o contexto depois. */
    public function actingAs(string|int|null $userId, Closure $callback, ?string $connection = null): mixed
    {
        $db = $this->connection($connection);
        $previous = $db->selectOne("select nullif(current_setting('audit.user_id', true), '') as v")->v;

        $this->setContext(['user_id' => (string) $userId], $connection);

        try {
            return $callback();
        } finally {
            $this->setContext(['user_id' => (string) $previous], $connection);
        }
    }

    protected function columnList(array $columns): string
    {
        foreach ($columns as $c) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $c)) {
                throw new InvalidArgumentException("Nome de coluna inválido: {$c}");
            }
        }

        return implode(',', array_unique($columns));
    }

    protected function quoteTable(Connection $db, string $table): string
    {
        return $db->getQueryGrammar()->wrapTable($table);
    }
}
