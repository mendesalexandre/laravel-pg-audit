<?php

return [
    // Conexão do banco (null = padrão). Precisa ser pgsql.
    'connection' => env('PG_AUDIT_CONNECTION'),

    // Como o contexto (usuário/IP) chega ao trigger:
    //  'session'     -> set_config na sessão do banco (padrão; NÃO use com PgBouncer em modo transaction)
    //  'transaction' -> set_config local a cada transação (seguro com PgBouncer). Escritas fora de transação
    //                   ficam sem usuário; o middleware envolve o request em transação (wrap_request_in_transaction).
    'context_mode' => env('PG_AUDIT_CONTEXT_MODE', 'session'),
    'wrap_request_in_transaction' => true,

    // Métodos HTTP em que o middleware não define contexto (economiza idas ao banco), ex.: ['GET', 'HEAD', 'OPTIONS'].
    // Só use se seus GETs nunca gravam.
    'skip_methods' => [],

    // Conexões adicionais que também recebem o contexto (usuário/IP) no middleware.
    'context_connections' => [],

    // Schema e tabela padrão dos logs (pode ser sobrescrito por tabela auditada: audit(schema: ...)).
    'schema' => 'audit',
    'table' => 'logged_actions',

    // Nomes das colunas da tabela de log. 'locale' escolhe um preset ('en' ou 'pt_BR');
    // 'columns' sobrescreve nomes individuais. Defina ANTES de rodar as migrations de instalação
    // (o nome fica gravado na tabela e na função do trigger).
    //   chaves: id, schema, table, row_id, event, old_values, new_values, user_id,
    //           ip_address, user_agent, url, db_user, transaction_id, created_at
    'locale' => env('PG_AUDIT_LOCALE', 'en'),
    'columns' => [
        // 'row_id' => 'registro_id',
    ],

    // Prefixo para tabela de log POR tabela auditada (ex.: 'audit_' => audit_orders, audit_order_items).
    // null = todas usam a tabela única `table` acima.
    'table_prefix' => null,

    // Colunas nunca gravadas no log (aplicado a todas as tabelas auditadas).
    'global_except' => ['password', 'remember_token'],

    // Registrar TRUNCATE por padrão ao habilitar auditoria.
    'truncate' => true,

    // Dias de retenção para pg-audit:prune (null = nunca apaga).
    'retention_days' => null,

    // Model de usuário para AuditLog::user().
    'user_model' => env('PG_AUDIT_USER_MODEL', 'App\\Models\\User'),

    // Tabelas habilitadas por `php artisan pg-audit:sync`.
    // 'users' => ['except' => ['updated_at']]  ou apenas 'posts'
    'tables' => [],
];
