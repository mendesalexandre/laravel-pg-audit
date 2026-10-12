<?php

namespace MendesAlexandre\PgAudit\Database;

use Illuminate\Support\Facades\DB;

class AuditSchema
{
    public const TRIGGER_ROW = 'audit_trigger_row';

    public const TRIGGER_TRUNCATE = 'audit_trigger_stm';

    public static function up(?string $connection = null): void
    {
        $db = DB::connection($connection);
        $schema = self::q(config('pg-audit.schema'));
        $table = self::q(config('pg-audit.table'));

        $db->unprepared("CREATE SCHEMA IF NOT EXISTS {$schema}");

        $db->unprepared(<<<SQL
            CREATE TABLE IF NOT EXISTS {$schema}.{$table} (
                id             bigserial PRIMARY KEY,
                schema_name    text        NOT NULL,
                table_name     text        NOT NULL,
                row_pk         text,
                action         char(1)     NOT NULL CHECK (action IN ('I','U','D','T')),
                old_values     jsonb,
                new_values     jsonb,
                user_id        text,
                ip_address     text,
                user_agent     text,
                url            text,
                db_user        text        NOT NULL DEFAULT session_user,
                transaction_id bigint      NOT NULL DEFAULT txid_current(),
                created_at     timestamptz NOT NULL DEFAULT clock_timestamp()
            )
        SQL);

        $db->unprepared("CREATE INDEX IF NOT EXISTS logged_actions_table_row_idx ON {$schema}.{$table} (schema_name, table_name, row_pk)");
        $db->unprepared("CREATE INDEX IF NOT EXISTS logged_actions_created_at_idx ON {$schema}.{$table} (created_at)");
        $db->unprepared("CREATE INDEX IF NOT EXISTS logged_actions_user_idx ON {$schema}.{$table} (user_id)");

        self::createFunction($db, $schema, $table);
    }

    public static function down(?string $connection = null): void
    {
        $schema = self::q(config('pg-audit.schema'));

        // CASCADE remove também os triggers que usam a função.
        DB::connection($connection)->unprepared("DROP SCHEMA IF EXISTS {$schema} CASCADE");
    }

    /**
     * Função genérica de trigger. Argumento 0: colunas ignoradas separadas por vírgula.
     * Contexto lido de current_setting('audit.*'), preenchido pelo middleware/PgAudit::actingAs().
     */
    protected static function createFunction($db, string $schema, string $table): void
    {
        $db->unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION {$schema}.log_changes() RETURNS trigger
            LANGUAGE plpgsql AS \$fn\$
            DECLARE
                excluded   text[] := ARRAY[]::text[];
                old_row    jsonb;
                new_row    jsonb;
                old_vals   jsonb;
                new_vals   jsonb;
                pk         text;
            BEGIN
                IF TG_NARGS > 0 AND TG_ARGV[0] <> '' THEN
                    excluded := string_to_array(TG_ARGV[0], ',');
                END IF;

                IF TG_OP = 'TRUNCATE' THEN
                    INSERT INTO {$schema}.{$table} (schema_name, table_name, action, user_id, ip_address, user_agent, url)
                    VALUES (TG_TABLE_SCHEMA, TG_TABLE_NAME, 'T',
                        NULLIF(current_setting('audit.user_id', true), ''),
                        NULLIF(current_setting('audit.ip_address', true), ''),
                        NULLIF(current_setting('audit.user_agent', true), ''),
                        NULLIF(current_setting('audit.url', true), ''));
                    RETURN NULL;
                END IF;

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

                INSERT INTO {$schema}.{$table}
                    (schema_name, table_name, row_pk, action, old_values, new_values, user_id, ip_address, user_agent, url)
                VALUES (TG_TABLE_SCHEMA, TG_TABLE_NAME, pk, left(TG_OP, 1), old_vals, new_vals,
                    NULLIF(current_setting('audit.user_id', true), ''),
                    NULLIF(current_setting('audit.ip_address', true), ''),
                    NULLIF(current_setting('audit.user_agent', true), ''),
                    NULLIF(current_setting('audit.url', true), ''));

                RETURN NULL;
            END;
            \$fn\$
        SQL);
    }

    /** Escapa identificador SQL. */
    public static function q(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
