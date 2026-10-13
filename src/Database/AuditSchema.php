<?php

namespace MendesAlexandre\PgAudit\Database;

use Illuminate\Support\Facades\DB;
use MendesAlexandre\PgAudit\Support\Columns;

class AuditSchema
{
    public const TRIGGER_ROW = 'audit_trigger_row';

    public const TRIGGER_TRUNCATE = 'audit_trigger_stm';

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

        self::createFunction($db, $schema, $table);
    }

    /** Grava a versão do servidor PostgreSQL (ex.: 18.6) em cada linha? (config capture_db_version) */
    public static function capturesVersion(): bool
    {
        return (bool) config('pg-audit.capture_db_version', true);
    }

    /** Expressão SQL da versão curta do servidor. */
    public const VERSION_SQL = "split_part(current_setting('server_version'), ' ', 1)";

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
            $db->unprepared("ALTER TABLE {$qualified} DROP CONSTRAINT IF EXISTS {$name}");
            $db->unprepared("ALTER TABLE {$qualified} ADD CONSTRAINT {$name} CHECK ({$col['event']} IN (".self::eventsSql().'))');
        }

        self::createFunction($db, self::q($schemaName), self::q(self::ident($table ?? config('pg-audit.table'))));
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

        self::createFunction($db, self::q($schemaName), self::q(self::ident($table ?? config('pg-audit.table'))), $to);
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

        return [
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
                {$versionColumn}{$c['db_user']}        text        NOT NULL DEFAULT session_user,
                {$c['transaction_id']} bigint      NOT NULL DEFAULT txid_current(),
                {$c['created_at']}     timestamptz NOT NULL DEFAULT clock_timestamp()
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS '.$idx('row_idx')." ON {$quotedSchema}.{$quotedTable} ({$c['schema']}, {$c['table']}, {$c['row_id']})",
            'CREATE INDEX IF NOT EXISTS '.$idx('created_idx')." ON {$quotedSchema}.{$quotedTable} ({$c['created_at']})",
            'CREATE INDEX IF NOT EXISTS '.$idx('user_idx')." ON {$quotedSchema}.{$quotedTable} ({$c['user_id']})",
        ];
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
                    NULLIF(current_setting('audit.url', true), ''){$versionUsing};

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
                    NULLIF(current_setting('audit.url', true), ''){$versionValue})
            \$fn\$
        SQL);
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
