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

    /** Contexto em memória por conexão (escopo do request/job). */
    protected array $context = [];

    /** 'session' (set_config na sessão) ou 'transaction' (set_config local por transação; seguro p/ PgBouncer). */
    public function mode(): string
    {
        $mode = config('pg-audit.context_mode', 'session');

        return in_array($mode, ['session', 'transaction'], true)
            ? $mode
            : throw new InvalidArgumentException("pg-audit.context_mode inválido: {$mode}");
    }

    /**
     * Define o contexto lido pelo trigger (usuário, IP...).
     *  - session: set_config(..., false) agora, pela conexão de ESCRITA.
     *  - transaction: guarda em memória e aplica com set_config(..., true) a cada início de transação
     *    (e agora, se já houver uma aberta). Fora de transação não há contexto: use PgAudit::actingAs() ou o middleware.
     */
    public function setContext(array $context, ?string $connection = null): void
    {
        $context = array_map(fn ($v) => (string) ($v ?? ''), array_intersect_key($context, array_flip(self::CONTEXT_KEYS)));
        $key = $this->contextKey($connection);
        $this->context[$key] = array_merge($this->context[$key] ?? [], $context);

        $db = $this->connection($connection);
        if ($this->mode() === 'session') {
            $this->apply($db, $context, false);
        } elseif ($db->transactionLevel() > 0) {
            $this->apply($db, $context, true);
        }
    }

    public function clearContext(?string $connection = null): void
    {
        $blank = array_fill_keys(self::CONTEXT_KEYS, '');
        unset($this->context[$this->contextKey($connection)]);

        $db = $this->connection($connection);
        if ($this->mode() === 'session') {
            $this->apply($db, $blank, false);
        } elseif ($db->transactionLevel() > 0) {
            $this->apply($db, $blank, true);
        }
    }

    /** Chamado no início de cada transação (modo transaction). */
    public function applyStored(Connection $db): void
    {
        if ($this->mode() !== 'transaction') {
            return;
        }

        $context = $this->context[$this->contextKey($db->getName())] ?? [];
        if ($context) {
            $this->apply($db, $context, true);
        }
    }

    /**
     * Executa o callback atribuindo as alterações a $userId e restaura o contexto depois.
     * No modo transaction, abre uma transação se não houver uma, para o contexto valer nas escritas.
     */
    public function actingAs(string|int|null $userId, Closure $callback, ?string $connection = null): mixed
    {
        $key = $this->contextKey($connection);
        $previous = $this->context[$key]['user_id'] ?? '';

        $this->setContext(['user_id' => (string) $userId], $connection);

        try {
            $db = $this->connection($connection);

            return $this->mode() === 'transaction' && $db->transactionLevel() === 0
                ? $db->transaction($callback)
                : $callback();
        } finally {
            $this->setContext(['user_id' => $previous], $connection);
        }
    }

    /** Uma única ida ao banco, sempre pela conexão de escrita (o trigger roda nela). */
    protected function apply(Connection $db, array $context, bool $local): void
    {
        $calls = [];
        $bindings = [];
        foreach (self::CONTEXT_KEYS as $key) {
            if (array_key_exists($key, $context)) {
                $calls[] = 'set_config(?, ?, '.($local ? 'true' : 'false').')';
                array_push($bindings, "audit.{$key}", $context[$key]);
            }
        }

        if ($calls) {
            $db->select('select '.implode(', ', $calls), $bindings, false);
        }
    }

    protected function contextKey(?string $connection): string
    {
        return $connection ?? config('pg-audit.connection') ?? config('database.default');
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
