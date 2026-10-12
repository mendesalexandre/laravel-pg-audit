<?php

namespace MendesAlexandre\PgAudit\Support;

use InvalidArgumentException;

/**
 * Nomes físicos das colunas da tabela de log. Chaves lógicas fixas; nomes vêm do preset
 * (config "locale") e podem ser sobrescritos um a um em config "columns".
 */
class Columns
{
    public const PRESETS = [
        'en' => [
            'id' => 'id',
            'schema' => 'auditable_schema',
            'table' => 'auditable_table',
            'row_id' => 'auditable_id',
            'event' => 'event',
            'old_values' => 'old_values',
            'new_values' => 'new_values',
            'user_id' => 'user_id',
            'ip_address' => 'ip_address',
            'user_agent' => 'user_agent',
            'url' => 'url',
            'db_user' => 'db_user',
            'transaction_id' => 'transaction_id',
            'created_at' => 'created_at',
        ],
        'pt' => [
            'id' => 'id',
            'schema' => 'auditavel_schema',
            'table' => 'auditavel_tabela',
            'row_id' => 'auditavel_id',
            'event' => 'evento',
            'old_values' => 'valores_antigos',
            'new_values' => 'valores_novos',
            'user_id' => 'usuario_id',
            'ip_address' => 'ip',
            'user_agent' => 'navegador',
            'url' => 'url',
            'db_user' => 'usuario_banco',
            'transaction_id' => 'transacao_id',
            'created_at' => 'criado_em',
        ],
    ];

    /** Nomes efetivos segundo a config atual (preset + overrides). */
    public static function all(): array
    {
        return self::for(config('pg-audit.locale', 'en'), config('pg-audit.columns', []));
    }

    /** Nomes de um preset específico, com overrides opcionais. */
    public static function for(string $locale, array $overrides = []): array
    {
        $preset = self::PRESETS[$locale] ?? throw new InvalidArgumentException("Preset de colunas desconhecido: {$locale}");
        $columns = array_merge($preset, $overrides);

        foreach ($columns as $key => $name) {
            if (! array_key_exists($key, self::PRESETS['en'])) {
                throw new InvalidArgumentException("Chave de coluna desconhecida: {$key}");
            }
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                throw new InvalidArgumentException("Nome de coluna inválido: {$name}");
            }
        }

        return $columns;
    }

    public static function get(string $key): string
    {
        return self::all()[$key] ?? throw new InvalidArgumentException("Chave de coluna desconhecida: {$key}");
    }
}
