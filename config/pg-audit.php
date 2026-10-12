<?php

return [
    // Conexão do banco (null = padrão). Precisa ser pgsql.
    'connection' => env('PG_AUDIT_CONNECTION'),

    // Schema e tabela onde os logs ficam.
    'schema' => 'audit',
    'table' => 'logged_actions',

    // Colunas nunca gravadas no log (aplicado a todas as tabelas auditadas).
    'global_except' => ['remember_token'],

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
