<?php

namespace MendesAlexandre\PgAudit\Database;

use Illuminate\Support\Facades\DB;
use MendesAlexandre\PgAudit\Support\Columns;

class AuditSchema
{
    public const TRIGGER_ROW = 'audit_trigger_row';

    public const TRIGGER_TRUNCATE = 'audit_trigger_stm';

    /** Tabela de estado (1 linha) com a versão do esquema e do PostgreSQL, lida pelo trigger. */
    public const STATE_TABLE = 'schema_state';

    public const EVENT_TRIGGER_END = 'pg_audit_ddl_end';

    public const EVENT_TRIGGER_DROP = 'pg_audit_ddl_drop';

    /** I/U/D/T = operações; P/R = auditoria pausada/retomada. */
    public const EVENTS = ['I', 'U', 'D', 'T', 'P', 'R'];

    /** Instala schema, tabela de log e função. Todos os parâmetros têm padrão na config. */
    public static function up(?string $connection = null, ?string $schema = null, ?string $table = null): void
    {
        $db = DB::connection($connection ?? config('pg-audit.connection'));
        $schema = self::q(self::ident($schema ?? config('pg-audit.schema')));
        $table = self::q(self::ident($table ?? config('pg-audit.table')));

        $db->unprepared("CREATE SCHEMA IF NOT EXISTS {$schema}");

        foreach (self::logTableStatements($schema, $table) as $sql) {
            $db->unprepared($sql);
        }

        self::createSupportTables($db, $schema);
        self::createFunction($db, $schema, $table);
        self::createDdlFunctions($db, $schema);
    }

    /** Grava a versão do esquema (schema_state) em cada linha? (config capture_schema_version) */
    public static function capturesSchemaVersion(): bool
    {
        return (bool) config('pg-audit.capture_schema_version', true);
    }

    /** Grava a versão do servidor PostgreSQL (ex.: 18.6) em cada linha? (config capture_db_version) */
    public static function capturesVersion(): bool
    {
        return (bool) config('pg-audit.capture_db_version', true);
    }

    /** O texto da query (current_query()) pode ser gravado? Cria a coluna; cada tabela decide ao habilitar. */
    public static function capturesQuery(): bool
    {
        return (bool) config('pg-audit.capture_query', false);
    }

    /** Expressão SQL da versão curta do servidor. */
    public const VERSION_SQL = "split_part(current_setting('server_version'), ' ', 1)";

    /** Tabela de estado (versão do esquema/PostgreSQL) e tabela do histórico de DDL. Idempotente. */
    public static function createSupportTables($db, string $quotedSchema, ?array $ddlNames = null): void
    {
        $state = self::q(self::STATE_TABLE);
        $db->unprepared(<<<SQL
            CREATE TABLE IF NOT EXISTS {$quotedSchema}.{$state} (
                id         boolean PRIMARY KEY DEFAULT true CHECK (id),
                version    text,
                pg_version text,
                updated_at timestamptz NOT NULL DEFAULT clock_timestamp()
            )
        SQL);
        $db->unprepared("INSERT INTO {$quotedSchema}.{$state} (id, version, pg_version) VALUES (true, NULL, current_setting('server_version')) ON CONFLICT (id) DO NOTHING");

        $d = array_map(fn ($n) => self::q($n), $ddlNames ?? Columns::ddl());
        $ddl = self::q(self::ident(config('pg-audit.ddl_table', 'schema_changes')));
        $db->unprepared(<<<SQL
            CREATE TABLE IF NOT EXISTS {$quotedSchema}.{$ddl} (
                {$d['id']}             bigserial PRIMARY KEY,
                {$d['executed_at']}    timestamptz NOT NULL DEFAULT clock_timestamp(),
                {$d['db_user']}        text        NOT NULL DEFAULT session_user,
                {$d['user_id']}        text,
                {$d['command']}        text        NOT NULL,
                {$d['object_type']}    text,
                {$d['object']}         text,
                {$d['schema']}         text,
                {$d['query']}          text,
                {$d['migration']}      text,
                {$d['schema_version']} text,
                {$d['pg_version']}     text
            )
        SQL);
        $db->unprepared('CREATE INDEX IF NOT EXISTS '.self::q(substr(trim($ddl, '"').'_at_idx', 0, 63))." ON {$quotedSchema}.{$ddl} ({$d['executed_at']})");
        $db->unprepared('CREATE INDEX IF NOT EXISTS '.self::q(substr(trim($ddl, '"').'_obj_idx', 0, 63))." ON {$quotedSchema}.{$ddl} ({$d['object']})");
    }

    /** Nomes das colunas de DDL correspondentes a um conjunto de nomes das colunas de log (pelo preset de idioma). */
    protected static function ddlNamesFor(array $logColumns, bool $withOverrides): array
    {
        foreach (Columns::PRESETS as $locale => $preset) {
            if (($logColumns['created_at'] ?? null) === $preset['created_at'] && ($logColumns['event'] ?? null) === $preset['event']) {
                return $withOverrides ? array_merge(Columns::DDL_PRESETS[$locale], config('pg-audit.ddl_columns', [])) : Columns::DDL_PRESETS[$locale];
            }
        }

        return Columns::ddl();
    }

    public static function eventsSql(): string
    {
        return implode(',', array_map(fn ($e) => "'{$e}'", self::EVENTS));
    }

    public static function checkName(string $table, string $eventColumn): string
    {
        return self::q(substr($table.'_'.$eventColumn.'_check', 0, 63));
    }

    /**
     * Atualiza instalações existentes: recria a função do trigger (com suporte a pausa e log_event) e amplia
     * a constraint de eventos das tabelas de log (padrão e por prefixo) para aceitar P/R.
     */
    public static function refreshFunction(?string $connection = null, ?string $schema = null, ?string $table = null): void
    {
        $db = DB::connection($connection ?? config('pg-audit.connection'));
        $schemaName = self::ident($schema ?? config('pg-audit.schema'));
        $col = Columns::all();

        $tables = $db->select(<<<'SQL'
            SELECT table_name FROM information_schema.columns
             WHERE table_schema = ? AND column_name IN (?, ?)
             GROUP BY table_name HAVING count(*) = 2
        SQL, [$schemaName, $col['event'], $col['row_id']]);

        foreach ($tables as $t) {
            $name = self::checkName($t->table_name, $col['event']);
            $qualified = self::q($schemaName).'.'.self::q($t->table_name);
            if (self::capturesVersion()) {
                $db->unprepared("ALTER TABLE {$qualified} ADD COLUMN IF NOT EXISTS ".self::q($col['db_version']).' text');
            }
            if (self::capturesSchemaVersion()) {
                $db->unprepared("ALTER TABLE {$qualified} ADD COLUMN IF NOT EXISTS ".self::q($col['schema_version']).' text');
            }
            if (self::capturesQuery()) {
                $db->unprepared("ALTER TABLE {$qualified} ADD COLUMN IF NOT EXISTS ".self::q($col['query']).' text');
            }
            foreach (self::guardStatements(self::q($schemaName), self::q($t->table_name)) as $guard) {
                $db->unprepared($guard);
            }
            $db->unprepared("ALTER TABLE {$qualified} DROP CONSTRAINT IF EXISTS {$name}");
            $db->unprepared("ALTER TABLE {$qualified} ADD CONSTRAINT {$name} CHECK ({$col['event']} IN (".self::eventsSql().'))');
        }

        self::createSupportTables($db, self::q($schemaName));
        self::createFunction($db, self::q($schemaName), self::q(self::ident($table ?? config('pg-audit.table'))));
        self::createDdlFunctions($db, self::q($schemaName));
    }

    /**
     * Renomeia as colunas de todas as tabelas de log do schema (padrão e por prefixo) de $from para $to
     * (arrays chave lógica => nome, ver Columns) e recria a função do trigger com os nomes novos.
     * Triggers existentes continuam valendo.
     */
    public static function renameColumns(array $from, array $to, ?string $connection = null, ?string $schema = null, ?string $table = null): void
    {
        $db = DB::connection($connection ?? config('pg-audit.connection'));
        $schemaName = self::ident($schema ?? config('pg-audit.schema'));

        // Tabelas de log = as que têm as colunas de origem de evento e id da linha.
        $tables = $db->select(<<<'SQL'
            SELECT table_name FROM information_schema.columns
             WHERE table_schema = ? AND column_name IN (?, ?)
             GROUP BY table_name HAVING count(*) = 2
        SQL, [$schemaName, $from['event'], $from['row_id']]);

        foreach ($tables as $t) {
            $existing = array_column($db->select('SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ?', [$schemaName, $t->table_name]), 'column_name');
            foreach ($from as $key => $old) {
                if ($old !== ($to[$key] ?? $old) && in_array($old, $existing, true)) {
                    $db->unprepared('ALTER TABLE '.self::q($schemaName).'.'.self::q($t->table_name).' RENAME COLUMN '.self::q($old).' TO '.self::q(self::ident($to[$key])));
                }
            }
        }

        // tabela de DDL: acompanha o idioma dos nomes das colunas de log (origem/destino inferidos dos presets)
        $ddlFrom = self::ddlNamesFor($from, false);
        $ddlTo = self::ddlNamesFor($to, true);
        $ddlTable = self::ident(config('pg-audit.ddl_table', 'schema_changes'));
        $ddlExisting = array_map(fn ($c) => $c->column_name, $db->select(
            'SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ?', [$schemaName, $ddlTable]
        ));
        foreach ($ddlFrom as $key => $old) {
            if ($old !== $ddlTo[$key] && in_array($old, $ddlExisting, true)) {
                $db->unprepared('ALTER TABLE '.self::q($schemaName).'.'.self::q($ddlTable).' RENAME COLUMN '.self::q($old).' TO '.self::q($ddlTo[$key]));
            }
        }
        $quotedDdlTo = array_map(fn ($n) => self::q($n), $ddlTo);
        self::createSupportTables($db, self::q($schemaName), $ddlTo);
        self::createFunction($db, self::q($schemaName), self::q(self::ident($table ?? config('pg-audit.table'))), $to);
        self::createDdlFunctions($db, self::q($schemaName), $quotedDdlTo);
    }

    /**
     * SQL para criar uma tabela de log (idempotente). Recebe nomes já com quote.
     * Usada para a tabela padrão e para as tabelas por prefixo (ex.: audit_orders).
     */
    public static function logTableStatements(string $quotedSchema, string $quotedTable): array
    {
        $raw = trim($quotedTable, '"');
        $idx = fn (string $suffix) => self::q(substr($raw.'_'.$suffix, 0, 63));

        $c = array_map(fn ($n) => self::q($n), Columns::all());
        $events = self::eventsSql();
        $checkName = self::checkName($raw, Columns::get('event'));
        $versionColumn = self::capturesVersion() ? "{$c['db_version']}     text,\n                " : '';
        $versionColumn .= self::capturesSchemaVersion() ? "{$c['schema_version']} text,\n                " : '';
        $queryColumn = self::capturesQuery() ? "{$c['query']}          text,\n                " : '';

        $statements = [
            <<<SQL
            CREATE TABLE IF NOT EXISTS {$quotedSchema}.{$quotedTable} (
                {$c['id']}             bigserial PRIMARY KEY,
                {$c['schema']}         text        NOT NULL,
                {$c['table']}          text        NOT NULL,
                {$c['row_id']}         text,
                {$c['event']}          char(1)     NOT NULL CONSTRAINT {$checkName} CHECK ({$c['event']} IN ({$events})),
                {$c['old_values']}     jsonb,
                {$c['new_values']}     jsonb,
                {$c['user_id']}        text,
                {$c['ip_address']}     text,
                {$c['user_agent']}     text,
                {$c['url']}            text,
                {$versionColumn}{$queryColumn}{$c['db_user']}        text        NOT NULL DEFAULT session_user,
                {$c['transaction_id']} bigint      NOT NULL DEFAULT txid_current(),
                {$c['created_at']}     timestamptz NOT NULL DEFAULT clock_timestamp()
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS '.$idx('row_idx')." ON {$quotedSchema}.{$quotedTable} ({$c['schema']}, {$c['table']}, {$c['row_id']})",
            'CREATE INDEX IF NOT EXISTS '.$idx('created_idx')." ON {$quotedSchema}.{$quotedTable} ({$c['created_at']})",
            'CREATE INDEX IF NOT EXISTS '.$idx('user_idx')." ON {$quotedSchema}.{$quotedTable} ({$c['user_id']})",
        ];

        return array_merge($statements, self::guardStatements($quotedSchema, $quotedTable));
    }

    /** O log é somente de inclusão (trigger que barra UPDATE/DELETE/TRUNCATE)? config append_only */
    public static function appendOnly(): bool
    {
        return (bool) config('pg-audit.append_only', true);
    }

    public const GUARD_ROW = 'audit_log_guard_row';

    public const GUARD_STM = 'audit_log_guard_stm';

    /**
     * Trigger que impede alterar/apagar linhas do log por engano (bug, `AuditLog::delete()`, script).
     * Libera só dentro de PgAudit::withPurge() (usado pelo pg-audit:prune). NÃO é proteção contra quem tem acesso ao banco
     * (ele pode ligar o mesmo GUC ou dropar o trigger): para isso use pg-audit:grant com um role sem UPDATE/DELETE.
     */
    public static function guardStatements(string $quotedSchema, string $quotedTable): array
    {
        $row = self::q(self::GUARD_ROW);
        $stm = self::q(self::GUARD_STM);
        $target = "{$quotedSchema}.{$quotedTable}";
        $out = ["DROP TRIGGER IF EXISTS {$row} ON {$target}", "DROP TRIGGER IF EXISTS {$stm} ON {$target}"];

        if (! self::appendOnly()) {
            return $out;
        }

        return array_merge(
            [self::protectFunctionSql($quotedSchema)],
            $out,
            [
                "CREATE TRIGGER {$row} BEFORE UPDATE OR DELETE ON {$target} FOR EACH ROW EXECUTE FUNCTION {$quotedSchema}.protect_log()",
                "CREATE TRIGGER {$stm} BEFORE TRUNCATE ON {$target} FOR EACH STATEMENT EXECUTE FUNCTION {$quotedSchema}.protect_log()",
            ]
        );
    }

    protected static function protectFunctionSql(string $quotedSchema): string
    {
        return <<<SQL
            CREATE OR REPLACE FUNCTION {$quotedSchema}.protect_log() RETURNS trigger
            LANGUAGE plpgsql AS \$fn\$
            BEGIN
                IF current_setting('audit.allow_purge', true) = 'on' THEN
                    RETURN CASE TG_OP WHEN 'DELETE' THEN OLD WHEN 'UPDATE' THEN NEW ELSE NULL END;
                END IF;

                RAISE EXCEPTION 'O log de auditoria é somente de inclusão (% em %.%). Para apagar por retenção use pg-audit:prune.',
                    TG_OP, TG_TABLE_SCHEMA, TG_TABLE_NAME
                    USING ERRCODE = 'insufficient_privilege';
            END;
            \$fn\$
        SQL;
    }

    /**
     * Permissões mínimas para o role do app: só INSERT nas tabelas de log (nunca UPDATE/DELETE/TRUNCATE).
     * Os triggers rodam como o role que grava, então ele precisa disso para auditar. $read adiciona SELECT
     * (para a tela de histórico, pg-audit:deleted/restore). Vale para tabelas futuras do schema (default privileges).
     * Rode como o dono do schema de auditoria. Se o role for o próprio dono, REVOKE não tem efeito: use um role separado.
     *
     * @return array<int, string> avisos
     */
    public static function grantApp(string $role, bool $read = false, ?string $connection = null, ?string $schema = null): array
    {
        $db = DB::connection($connection ?? config('pg-audit.connection'));
        $schemaName = self::ident($schema ?? config('pg-audit.schema'));
        $s = self::q($schemaName);
        $r = self::q(self::ident($role));
        $warnings = [];

        $col = Columns::all();
        $logTables = array_column($db->select(<<<'SQL'
            SELECT table_name FROM information_schema.columns
             WHERE table_schema = ? AND column_name IN (?, ?) GROUP BY table_name HAVING count(*) = 2
        SQL, [$schemaName, $col['event'], $col['row_id']]), 'table_name');

        $owners = array_column($db->select('SELECT tablename, tableowner FROM pg_tables WHERE schemaname = ?', [$schemaName]), 'tableowner', 'tablename');

        $db->unprepared("GRANT USAGE ON SCHEMA {$s} TO {$r}");

        foreach ($logTables as $table) {
            $t = "{$s}.".self::q($table);
            if (($owners[$table] ?? null) === $role) {
                $warnings[] = "{$role} é o dono de {$schemaName}.{$table}: o dono sempre pode UPDATE/DELETE. Use um role separado.";
            }
            $db->unprepared("REVOKE ALL ON TABLE {$t} FROM {$r}");
            $db->unprepared("GRANT INSERT ON TABLE {$t} TO {$r}");
            if ($read) {
                $db->unprepared("GRANT SELECT ON TABLE {$t} TO {$r}");
            }
        }

        // o trigger lê a versão do esquema (schema_state) e a tabela de DDL é só leitura para o app
        if ($db->selectOne('SELECT to_regclass(?) IS NOT NULL AS ok', ["{$s}.".self::q(self::STATE_TABLE)])->ok) {
            $db->unprepared("GRANT SELECT ON TABLE {$s}.".self::q(self::STATE_TABLE)." TO {$r}");
        }

        $db->unprepared("GRANT USAGE ON ALL SEQUENCES IN SCHEMA {$s} TO {$r}");
        $db->unprepared("ALTER DEFAULT PRIVILEGES IN SCHEMA {$s} GRANT INSERT ON TABLES TO {$r}");
        $db->unprepared("ALTER DEFAULT PRIVILEGES IN SCHEMA {$s} GRANT USAGE ON SEQUENCES TO {$r}");
        if ($read) {
            $db->unprepared("ALTER DEFAULT PRIVILEGES IN SCHEMA {$s} GRANT SELECT ON TABLES TO {$r}");
        }

        return $warnings;
    }

    public static function down(?string $connection = null, ?string $schema = null): void
    {
        $schema = self::q($schema ?? config('pg-audit.schema'));

        // CASCADE remove também os triggers que usam a função.
        DB::connection($connection ?? config('pg-audit.connection'))->unprepared("DROP SCHEMA IF EXISTS {$schema} CASCADE");
    }

    /**
     * Função genérica de trigger.
     *  - TG_ARGV[0]: colunas ignoradas (separadas por vírgula)
     *  - TG_ARGV[1]: (opcional) tabela de log neste schema; vazio = tabela padrão
     * Contexto lido de current_setting('audit.*'), preenchido pelo middleware/PgAudit::actingAs().
     */
    protected static function createFunction($db, string $schema, string $table, ?array $columns = null): void
    {
        $literalSchema = trim($schema, '"');
        $col = $columns ?? Columns::all();
        $list = [$col['schema'], $col['table'], $col['row_id'], $col['event'], $col['old_values'], $col['new_values'], $col['user_id'], $col['ip_address'], $col['user_agent'], $col['url']];
        $eventList = [$col['schema'], $col['table'], $col['event'], $col['new_values'], $col['user_id'], $col['ip_address'], $col['user_agent'], $col['url']];
        $version = self::capturesVersion();
        if ($version) {
            $list[] = $eventList[] = $col['db_version'];
        }
        $schemaVersion = self::capturesSchemaVersion();
        if ($schemaVersion) {
            $list[] = $eventList[] = $col['schema_version'];
        }
        $query = self::capturesQuery();
        if ($query) {
            $list[] = $col['query'];
        }
        $stateTable = self::q(self::STATE_TABLE);
        $schemaVersionValue = $schemaVersion ? ",\n                    (SELECT version FROM {$schema}.{$stateTable} LIMIT 1)" : '';
        $queryUsing = $query ? ",\n                    CASE WHEN capture_q THEN left(current_query(), ".max(1, (int) config('pg-audit.query_max_length', 2000)).') END' : '';
        $names = implode(', ', $list);
        $eventNames = implode(', ', $eventList);
        $placeholders = implode(', ', array_map(fn ($i) => '$'.$i, range(1, count($list))));
        $versionUsing = $version ? ",\n                    ".self::VERSION_SQL : '';
        $versionValue = $version ? ",\n                    ".self::VERSION_SQL : '';
        $literalTable = trim($table, '"');

        $db->unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION {$schema}.log_changes() RETURNS trigger
            LANGUAGE plpgsql AS \$fn\$
            DECLARE
                excluded   text[] := ARRAY[]::text[];
                log_table  text   := '{$literalTable}';
                old_row    jsonb;
                new_row    jsonb;
                old_vals   jsonb;
                new_vals   jsonb;
                pk         text;
                paused     text;
                capture_q  boolean := TG_NARGS > 2 AND TG_ARGV[2] = 'q';
            BEGIN
                -- auditoria pausada nesta sessão ('*' = tudo, ou lista de tabelas separadas por vírgula)
                paused := NULLIF(current_setting('audit.paused', true), '');
                IF paused IS NOT NULL AND (paused = '*'
                   OR TG_TABLE_NAME = ANY (string_to_array(paused, ','))
                   OR TG_TABLE_SCHEMA || '.' || TG_TABLE_NAME = ANY (string_to_array(paused, ','))) THEN
                    RETURN NULL;
                END IF;

                IF TG_NARGS > 0 AND TG_ARGV[0] <> '' THEN
                    excluded := string_to_array(TG_ARGV[0], ',');
                END IF;
                IF TG_NARGS > 1 AND TG_ARGV[1] <> '' THEN
                    log_table := TG_ARGV[1];
                END IF;

                IF TG_OP <> 'TRUNCATE' THEN
                    IF TG_OP IN ('UPDATE', 'DELETE') THEN old_row := to_jsonb(OLD) - excluded; END IF;
                    IF TG_OP IN ('UPDATE', 'INSERT') THEN new_row := to_jsonb(NEW) - excluded; END IF;

                    IF TG_OP = 'UPDATE' THEN
                        SELECT jsonb_object_agg(n.key, n.value) INTO new_vals
                          FROM jsonb_each(new_row) n
                         WHERE old_row -> n.key IS DISTINCT FROM n.value;

                        IF new_vals IS NULL THEN
                            RETURN NULL; -- nada relevante mudou
                        END IF;

                        SELECT jsonb_object_agg(k, old_row -> k) INTO old_vals
                          FROM jsonb_object_keys(new_vals) k;
                    ELSIF TG_OP = 'INSERT' THEN
                        new_vals := new_row;
                    ELSE
                        old_vals := old_row;
                    END IF;

                    SELECT string_agg(COALESCE(
                               CASE WHEN TG_OP = 'DELETE' THEN to_jsonb(OLD) ELSE to_jsonb(NEW) END ->> a.attname, ''),
                               ',' ORDER BY k.ord)
                      INTO pk
                      FROM pg_index i
                      CROSS JOIN LATERAL unnest(i.indkey) WITH ORDINALITY AS k(attnum, ord)
                      JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = k.attnum
                     WHERE i.indrelid = TG_RELID AND i.indisprimary;
                END IF;

                EXECUTE format(
                    'INSERT INTO %I.%I ({$names})
                     VALUES ({$placeholders})',
                    '{$literalSchema}', log_table)
                USING TG_TABLE_SCHEMA, TG_TABLE_NAME, pk, left(TG_OP, 1), old_vals, new_vals,
                    NULLIF(current_setting('audit.user_id', true), ''),
                    NULLIF(current_setting('audit.ip_address', true), ''),
                    NULLIF(current_setting('audit.user_agent', true), ''),
                    NULLIF(current_setting('audit.url', true), ''){$versionUsing}{$schemaVersionValue}{$queryUsing};

                RETURN NULL;
            END;
            \$fn\$
        SQL);

        // Registra eventos que não vêm de uma linha (pausa/retomada), com o mesmo contexto de usuário/IP.
        $db->unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION {$schema}.log_event(p_event char, p_subject text, p_details jsonb) RETURNS void
            LANGUAGE sql AS \$fn\$
                INSERT INTO {$schema}.{$table}
                    ({$eventNames})
                VALUES ('{$literalSchema}', p_subject, p_event, p_details,
                    NULLIF(current_setting('audit.user_id', true), ''),
                    NULLIF(current_setting('audit.ip_address', true), ''),
                    NULLIF(current_setting('audit.user_agent', true), ''),
                    NULLIF(current_setting('audit.url', true), ''){$versionValue}{$schemaVersionValue})
            \$fn\$
        SQL);
    }

    /**
     * Funções dos event triggers de DDL. SECURITY DEFINER: quem roda o DDL não precisa de permissão no schema de auditoria.
     * Não registra objetos do próprio schema de auditoria, temporários, nem de extensões.
     */
    public static function createDdlFunctions($db, string $schema, ?array $names = null): void
    {
        $literalSchema = trim($schema, '"');
        $d = $names ?? array_map(fn ($n) => self::q($n), Columns::ddl());
        $ddl = self::q(self::ident(config('pg-audit.ddl_table', 'schema_changes')));
        $state = self::q(self::STATE_TABLE);
        $cols = implode(', ', [$d['db_user'], $d['user_id'], $d['command'], $d['object_type'], $d['object'], $d['schema'], $d['query'], $d['migration'], $d['schema_version'], $d['pg_version']]);
        $ctx = "NULLIF(current_setting('audit.user_id', true), ''), %s, r.object_type, r.object_identity, r.schema_name, current_query(), NULLIF(current_setting('audit.migration', true), ''), ver, current_setting('server_version')";

        $insert = fn (string $tag) => "INSERT INTO {$schema}.{$ddl} ({$cols}) VALUES (session_user, ".sprintf($ctx, $tag).')';

        $db->unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION {$schema}.log_ddl() RETURNS event_trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS \$fn\$
            DECLARE r record; ver text;
            BEGIN
                SELECT version INTO ver FROM {$schema}.{$state} LIMIT 1;
                FOR r IN SELECT * FROM pg_event_trigger_ddl_commands() LOOP
                    CONTINUE WHEN r.in_extension OR r.schema_name IS NOT DISTINCT FROM '{$literalSchema}' OR r.schema_name LIKE 'pg\_temp%';
                    {$insert('r.command_tag')};
                END LOOP;
            END;
            \$fn\$
        SQL);

        $db->unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION {$schema}.log_ddl_drop() RETURNS event_trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS \$fn\$
            DECLARE r record; ver text;
            BEGIN
                SELECT version INTO ver FROM {$schema}.{$state} LIMIT 1;
                FOR r IN SELECT * FROM pg_event_trigger_dropped_objects() WHERE original AND NOT is_temporary LOOP
                    CONTINUE WHEN r.schema_name IS NOT DISTINCT FROM '{$literalSchema}';
                    {$insert('tg_tag')};
                END LOOP;
            END;
            \$fn\$
        SQL);
    }

    /** SQL dos event triggers (para um superusuário rodar, se o app não for). */
    public static function ddlLogStatements(?string $schema = null): array
    {
        $s = self::q(self::ident($schema ?? config('pg-audit.schema')));

        return [
            'DROP EVENT TRIGGER IF EXISTS '.self::EVENT_TRIGGER_END,
            'DROP EVENT TRIGGER IF EXISTS '.self::EVENT_TRIGGER_DROP,
            'CREATE EVENT TRIGGER '.self::EVENT_TRIGGER_END." ON ddl_command_end EXECUTE FUNCTION {$s}.log_ddl()",
            'CREATE EVENT TRIGGER '.self::EVENT_TRIGGER_DROP." ON sql_drop EXECUTE FUNCTION {$s}.log_ddl_drop()",
        ];
    }

    /** Liga o histórico de DDL. Exige superusuário. */
    public static function enableDdlLog(?string $connection = null, ?string $schema = null): void
    {
        $db = DB::connection($connection ?? config('pg-audit.connection'));
        $q = self::q(self::ident($schema ?? config('pg-audit.schema')));
        self::createSupportTables($db, $q);
        self::createDdlFunctions($db, $q);
        foreach (self::ddlLogStatements($schema) as $sql) {
            $db->unprepared($sql);
        }
    }

    public static function disableDdlLog(?string $connection = null): void
    {
        $db = DB::connection($connection ?? config('pg-audit.connection'));
        $db->unprepared('DROP EVENT TRIGGER IF EXISTS '.self::EVENT_TRIGGER_END);
        $db->unprepared('DROP EVENT TRIGGER IF EXISTS '.self::EVENT_TRIGGER_DROP);
    }

    public static function ddlLogEnabled(?string $connection = null): bool
    {
        return (bool) DB::connection($connection ?? config('pg-audit.connection'))
            ->selectOne('SELECT count(*) > 0 AS on FROM pg_event_trigger WHERE evtname IN (?, ?)', [self::EVENT_TRIGGER_END, self::EVENT_TRIGGER_DROP])->on;
    }

    /** Valida nome simples de schema/tabela (letras, números e _). */
    public static function ident(string $name): string
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("Identificador inválido: {$name}");
        }

        return $name;
    }

    /** Escapa identificador SQL. */
    public static function q(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
