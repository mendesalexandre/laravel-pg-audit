<?php

namespace MendesAlexandre\PgAudit;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use MendesAlexandre\PgAudit\Database\AuditSchema;

class AuditManager
{
    protected const CONTEXT_KEYS = ['user_id', 'ip_address', 'user_agent', 'url'];

    public function connection(): Connection
    {
        return DB::connection(config('pg-audit.connection'));
    }

    /** SQL (array) para habilitar auditoria numa tabela já qualificada/quoted. */
    public function enableStatements(string $quotedTable, array $except = [], ?bool $truncate = null): array
    {
        $schema = AuditSchema::q(config('pg-audit.schema'));
        $columns = $this->columnList(array_merge(config('pg-audit.global_except', []), $except));
        $arg = "'".$columns."'";
        $row = AuditSchema::q(AuditSchema::TRIGGER_ROW);
        $stm = AuditSchema::q(AuditSchema::TRIGGER_TRUNCATE);

        $sql = [
            "DROP TRIGGER IF EXISTS {$row} ON {$quotedTable}",
            "CREATE TRIGGER {$row} AFTER INSERT OR UPDATE OR DELETE ON {$quotedTable} FOR EACH ROW EXECUTE FUNCTION {$schema}.log_changes({$arg})",
            "DROP TRIGGER IF EXISTS {$stm} ON {$quotedTable}",
        ];

        if ($truncate ?? config('pg-audit.truncate', true)) {
            $sql[] = "CREATE TRIGGER {$stm} AFTER TRUNCATE ON {$quotedTable} FOR EACH STATEMENT EXECUTE FUNCTION {$schema}.log_changes({$arg})";
        }

        return $sql;
    }

    public function disableStatements(string $quotedTable): array
    {
        return [
            'DROP TRIGGER IF EXISTS '.AuditSchema::q(AuditSchema::TRIGGER_ROW)." ON {$quotedTable}",
            'DROP TRIGGER IF EXISTS '.AuditSchema::q(AuditSchema::TRIGGER_TRUNCATE)." ON {$quotedTable}",
        ];
    }

    public function enable(string $table, array $except = [], ?bool $truncate = null): void
    {
        $db = $this->connection();
        foreach ($this->enableStatements($this->quoteTable($db, $table), $except, $truncate) as $sql) {
            $db->unprepared($sql);
        }
    }

    public function disable(string $table): void
    {
        $db = $this->connection();
        foreach ($this->disableStatements($this->quoteTable($db, $table)) as $sql) {
            $db->unprepared($sql);
        }
    }

    /** Tabelas com auditoria ativa: [['table' => 'public.users', 'except' => 'a,b'], ...] */
    public function audited(): array
    {
        $rows = $this->connection()->select(<<<'SQL'
            SELECT n.nspname AS schema, c.relname AS table, pg_get_triggerdef(t.oid) AS def
              FROM pg_trigger t
              JOIN pg_class c ON c.oid = t.tgrelid
              JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE NOT t.tgisinternal AND t.tgname = ?
             ORDER BY n.nspname, c.relname
        SQL, [AuditSchema::TRIGGER_ROW]);

        return array_map(function ($r) {
            preg_match("/log_changes\\('([^']*)'\\)/", $r->def, $m);

            return ['table' => "{$r->schema}.{$r->table}", 'except' => $m[1] ?? ''];
        }, $rows);
    }

    /** Define contexto da sessão do banco (lido pelo trigger). */
    public function setContext(array $context): void
    {
        $db = $this->connection();
        foreach (self::CONTEXT_KEYS as $key) {
            if (array_key_exists($key, $context)) {
                $db->select('select set_config(?, ?, false)', ["audit.{$key}", (string) ($context[$key] ?? '')]);
            }
        }
    }

    public function clearContext(): void
    {
        $this->setContext(array_fill_keys(self::CONTEXT_KEYS, ''));
    }

    /** Executa o callback atribuindo as alterações a $userId e restaura o contexto depois. */
    public function actingAs(string|int|null $userId, Closure $callback): mixed
    {
        $db = $this->connection();
        $previous = $db->selectOne("select nullif(current_setting('audit.user_id', true), '') as v")->v;

        $this->setContext(['user_id' => (string) $userId]);

        try {
            return $callback();
        } finally {
            $this->setContext(['user_id' => (string) $previous]);
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
