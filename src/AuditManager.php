<?php

namespace MendesAlexandre\PgAudit;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use LogicException;
use MendesAlexandre\PgAudit\Database\AuditSchema;

class AuditManager
{
    protected const CONTEXT_KEYS = ['user_id', 'ip_address', 'user_agent', 'url', 'paused'];

    public function connection(?string $name = null): Connection
    {
        return DB::connection($name ?? config('pg-audit.connection'));
    }

    /**
     * SQL (array) para habilitar auditoria numa tabela.
     * $prefix (ou config "table_prefix") cria uma tabela de log própria: prefixo + nome da tabela (ex.: audit_orders).
     */
    public function enableStatements(string $quotedTable, array $except = [], ?bool $truncate = null, ?string $schema = null, ?string $prefix = null, ?bool $captureQuery = null): array
    {
        $schemaName = AuditSchema::ident($schema ?? config('pg-audit.schema'));
        $schema = AuditSchema::q($schemaName);
        $columns = $this->columnList(array_merge(config('pg-audit.global_except', []), $except));
        $args = "'".$columns."'";
        $sql = [];

        $logTable = $this->logTableName($quotedTable, $prefix);
        if ($logTable !== null) {
            $sql = AuditSchema::logTableStatements($schema, AuditSchema::q($logTable));
        }

        // 3º argumento 'q' liga a gravação do current_query() nesta tabela (a 2ª posição é a tabela de log, vazia = padrão).
        $query = $this->shouldCaptureQuery($quotedTable, $captureQuery);
        if ($logTable !== null || $query) {
            $args .= ", '".($logTable ?? '')."'";
        }
        if ($query) {
            $args .= ", 'q'";
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

    /** Config global capture_query + lista capture_query_tables (vazia = todas); $override força por tabela. */
    protected function shouldCaptureQuery(string $quotedTable, ?bool $override): bool
    {
        if (! AuditSchema::capturesQuery()) {
            if ($override) {
                throw new InvalidArgumentException('Defina pg-audit.capture_query = true (e rode pg-audit:upgrade em instalações antigas) antes de gravar a query.');
            }

            return false;
        }

        if ($override !== null) {
            return $override;
        }

        $only = config('pg-audit.capture_query_tables', []);
        $base = trim(Arr::last(explode('.', $quotedTable)), '"');
        $qualified = str_replace('"', '', $quotedTable);

        return $only === [] || in_array($base, $only, true) || in_array($qualified, $only, true);
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

    public function enable(string $table, array $except = [], ?bool $truncate = null, ?string $schema = null, ?string $connection = null, ?string $prefix = null, ?bool $captureQuery = null): void
    {
        $db = $this->connection($connection);
        foreach ($this->enableStatements($this->quoteTable($db, $table), $except, $truncate, $schema, $prefix, $captureQuery) as $sql) {
            $db->unprepared($sql);
        }
    }

    /** Habilita usando a configuração declarada no model (trait HasAuditLog). Colunas extras em $except somam às do model. */
    public function enableModel(string $modelClass, array $except = [], ?bool $truncate = null): void
    {
        $model = new $modelClass;
        if (! method_exists($model, 'auditConfig')) {
            throw new InvalidArgumentException("{$modelClass} precisa usar o trait HasAuditLog.");
        }

        $c = $model->auditConfig();
        $this->enable($c['table'], array_merge($c['except'], $except), $truncate, $c['schema'], $c['connection'], $c['prefix'], $c['capture_query']);
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
            preg_match("/EXECUTE FUNCTION (?:(.+)\\.)?log_changes\\('([^']*)'(?:, '([^']*)')?(?:, '([^']*)')?\\)/", $r->def, $m);

            return ['table' => "{$r->schema}.{$r->table}", 'audit_schema' => trim($m[1] ?? '', '"') ?: '(search_path)', 'log_table' => ($m[3] ?? '') ?: config('pg-audit.table'), 'captures_query' => ($m[4] ?? '') === 'q', 'except' => $m[2] ?? ''];
        }, $rows);
    }

    /**
     * Grava a versão do esquema (e a versão do PostgreSQL) na tabela de estado lida pelo trigger.
     * Sem $version: config "schema_version" ou, se vazia, o nome da última migration aplicada.
     */
    public function recordSchemaVersion(?string $version = null, ?string $connection = null): ?string
    {
        $db = $this->connection($connection);
        $version ??= config('pg-audit.schema_version') ?: $this->latestMigration($db);

        $state = AuditSchema::q(AuditSchema::ident(config('pg-audit.schema'))).'.'.AuditSchema::q(AuditSchema::STATE_TABLE);
        $db->statement(
            "INSERT INTO {$state} (id, version, pg_version, updated_at) VALUES (true, ?, current_setting('server_version'), clock_timestamp())
             ON CONFLICT (id) DO UPDATE SET version = EXCLUDED.version, pg_version = EXCLUDED.pg_version, updated_at = EXCLUDED.updated_at",
            [$version]
        );

        return $version;
    }

    /** Estado atual: version, pg_version, updated_at (ou null se a tabela de estado não existir). */
    public function schemaState(?string $connection = null): ?object
    {
        $db = $this->connection($connection);
        $state = AuditSchema::q(AuditSchema::ident(config('pg-audit.schema'))).'.'.AuditSchema::q(AuditSchema::STATE_TABLE);
        if (! $db->selectOne('SELECT to_regclass(?) IS NOT NULL AS ok', [$state])->ok) {
            return null;
        }

        return $db->selectOne("SELECT version, pg_version, updated_at FROM {$state} LIMIT 1");
    }

    protected function latestMigration(Connection $db): ?string
    {
        $table = config('database.migrations.table', config('database.migrations', 'migrations'));
        if (is_array($table)) {
            $table = $table['table'] ?? 'migrations';
        }

        return $db->selectOne('SELECT to_regclass(?) IS NOT NULL AS ok', [$table])->ok
            ? $db->table($table)->orderByDesc('id')->value('migration')
            : null;
    }

    /** Marca na sessão qual migration está rodando (lido pelo event trigger de DDL). */
    public function setMigration(?string $name, ?string $connection = null): void
    {
        $this->connection($connection)->select("select set_config('audit.migration', ?, false)", [(string) $name], false);
    }

    /** Nome do arquivo da migration (também para classes anônimas, cujo nome da classe contém o caminho). */
    public static function migrationName(object $migration): string
    {
        $class = get_class($migration);
        if (preg_match('/(\d{4}_\d{2}_\d{2}_\d{6}_[A-Za-z0-9_]+)\.php/', $class, $m)) {
            return $m[1];
        }

        return class_basename($class);
    }

    /**
     * Executa $callback com ATTR_EMULATE_PREPARES ligado só nesta conexão, restaurando depois (sem o pacote opcional
     * mpyw/laravel-pdo-emulation-control, só na sessão de escrita).
     * Com prepares emulados o SQL gravado por capture_query leva os valores reais em vez de $1, $2. Use apenas ao gravar
     * em tabelas SEM dados sensíveis: os valores também aparecem no log do servidor e no pg_stat_activity, e as colunas
     * em `except` NÃO são mascaradas no texto da query.
     */
    public function withLiteralQueries(Closure $callback, ?string $connection = null): mixed
    {
        $db = $this->connection($connection);

        // Se mpyw/laravel-pdo-emulation-control estiver instalado e registrado (ConnectionServiceProvider em config/app.php),
        // usa-o: cobre os PDOs de leitura e escrita, não força a conexão e restaura mesmo em blocos aninhados.
        if (method_exists($db, 'emulated')) {
            return $db->emulated($callback);
        }

        $pdo = $db->getPdo();
        $previous = $pdo->getAttribute(\PDO::ATTR_EMULATE_PREPARES);
        $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);

        try {
            return $callback();
        } finally {
            $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, $previous);
        }
    }

    /**
     * Decide, para um statement, se os valores podem ir embutidos no SQL (config literal_query_tables):
     * só INSERT/UPDATE/DELETE em tabela da lista e que não cita coluna sensível (global_except / literal_query_except).
     */
    public function shouldEmbedValues(string $sql): bool
    {
        $tables = config('pg-audit.literal_query_tables', []);
        if (! $tables || ! preg_match('/^\s*(?:insert\s+into|update|delete\s+from)\s+(?:only\s+)?(?:"?\w+"?\.)?"?(\w+)"?/i', $sql, $m)) {
            return false;
        }
        if (! in_array($m[1], $tables, true) && ! in_array('*', $tables, true)) {
            return false;
        }

        foreach (array_merge(config('pg-audit.global_except', []), config('pg-audit.literal_query_except', [])) as $column) {
            if (preg_match('/\b'.preg_quote($column, '/').'\b/i', $sql)) {
                return false;
            }
        }

        return true;
    }

    /** Antes de cada statement da conexão, liga/desliga ATTR_EMULATE_PREPARES conforme shouldEmbedValues(). */
    public function hookLiteralQueries(Connection $connection): void
    {
        $connection->beforeExecuting(function (string $query, array $bindings, Connection $conn) {
            if (! config('pg-audit.literal_query_tables')) {
                return;
            }
            $want = $this->shouldEmbedValues($query);
            $pdo = $conn->getPdo();
            if ((bool) $pdo->getAttribute(\PDO::ATTR_EMULATE_PREPARES) !== $want) {
                $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, $want);
            }
        });
    }

    /** O PDO desta conexão (escrita) está com prepares emulados? */
    public function emulatesPrepares(?string $connection = null): bool
    {
        return (bool) $this->connection($connection)->getPdo()->getAttribute(\PDO::ATTR_EMULATE_PREPARES);
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

    /**
     * Pausa a auditoria NESTA sessão do banco (outras conexões continuam auditadas), registrando quem pausou.
     * $tables: null = todas; ou nomes ('orders' / 'public.orders'). Prefira withoutAuditing(), que retoma sozinho.
     */
    public function pause(string|array|null $tables = null, ?string $reason = null, ?string $connection = null): void
    {
        $db = $this->connection($connection);
        if ($this->mode() === 'transaction' && $db->transactionLevel() === 0) {
            throw new LogicException('No modo transaction a pausa só vale dentro de uma transação: use PgAudit::withoutAuditing() ou DB::transaction().');
        }

        $subject = $tables === null ? '*' : implode(',', (array) $tables);
        if (! preg_match('/^(\*|[A-Za-z0-9_.]+(,[A-Za-z0-9_.]+)*)$/', $subject)) {
            throw new InvalidArgumentException("Tabelas inválidas para pausar: {$subject}");
        }

        $this->logEvent($db, 'P', $subject, $reason);          // registra antes de pausar
        $this->setContext(['paused' => $subject], $connection);
    }

    /** Retoma a auditoria (e registra). Sem efeito se não estava pausada. */
    public function resume(?string $connection = null): void
    {
        $this->restorePaused('', $connection);
    }

    public function isPaused(?string $connection = null): bool
    {
        return ($this->context[$this->contextKey($connection)]['paused'] ?? '') !== '';
    }

    /**
     * Executa o callback com a auditoria pausada nesta sessão (tudo ou só $tables) e retoma ao final,
     * inclusive se lançar exceção. Pausa e retomada ficam registradas no log. No modo transaction, abre transação.
     */
    public function withoutAuditing(Closure $callback, string|array|null $tables = null, ?string $reason = null, ?string $connection = null): mixed
    {
        $db = $this->connection($connection);
        $previous = $this->context[$this->contextKey($connection)]['paused'] ?? '';

        $run = function () use ($callback, $tables, $reason, $connection, $previous) {
            $this->pause($tables, $reason, $connection);

            try {
                return $callback();
            } finally {
                $this->restorePaused($previous, $connection);
            }
        };

        return $this->mode() === 'transaction' && $db->transactionLevel() === 0 ? $db->transaction($run) : $run();
    }

    protected function restorePaused(string $previous, ?string $connection): void
    {
        $key = $this->contextKey($connection);
        $current = $this->context[$key]['paused'] ?? '';
        if ($current === '') {
            return;
        }

        $this->setContext(['paused' => $previous], $connection);   // retoma antes de registrar
        $this->logEvent($this->connection($connection), 'R', $current, null);
    }

    protected function logEvent(Connection $db, string $event, string $subject, ?string $reason): void
    {
        $schema = AuditSchema::q(AuditSchema::ident(config('pg-audit.schema')));
        $details = json_encode(array_filter(['tables' => $subject, 'reason' => $reason], fn ($v) => $v !== null));

        $db->select("select {$schema}.log_event(?, ?, ?::jsonb)", [$event, $subject, $details], false);
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
