# Changelog

## 0.1.0

Primeira versão.

- Triggers de auditoria em PostgreSQL 16–18 com log em `jsonb` (`old_values`/`new_values`; em update só as colunas alteradas).
- `$table->audit()` nas migrations, `pg-audit:enable|disable|sync|status|make-migration|install|upgrade|rename-columns|prune|grant`.
- Contexto de quem fez (usuário, IP, user-agent, URL) por middleware e `PgAudit::actingAs()`; modo `transaction` para PgBouncer; Octane.
- Schema, tabela, conexão e tabela de log por prefixo configuráveis por tabela; nomes de colunas `en` e `pt_BR`.
- Pausa por sessão com registro (`PgAudit::withoutAuditing()`), versão do PostgreSQL e do esquema por linha, histórico de DDL (opcional, exige superusuário).
- `capture_query` opcional, valores reais na query só em tabelas escolhidas.
- Restaurar deletes e desfazer updates (`pg-audit:deleted`, `pg-audit:restore`), inclusive em cascata, com detecção de conflito.
- Log somente de inclusão (trigger) e `pg-audit:grant` para um role do app sem UPDATE/DELETE.
