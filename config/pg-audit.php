<?php

return [
    // Conexão do banco (null = padrão). Precisa ser pgsql.
    'connection' => env('PG_AUDIT_CONNECTION'),

    // Conexões adicionais que também recebem o contexto (usuário/IP) no middleware.
    'context_connections' => [],

    // Schema e tabela padrão dos logs (pode ser sobrescrito por tabela auditada: audit(schema: ...)).
    'schema' => 'audit',
    'table' => 'logged_actions',

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
