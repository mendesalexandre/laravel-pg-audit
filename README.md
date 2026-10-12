# laravel-pg-audit

Auditoria automática para Laravel usando **triggers do PostgreSQL** (testado no PostgreSQL 16–18).
Inspirado no [audit-trigger 91plus](https://wiki.postgresql.org/wiki/Audit_trigger_91plus), com log em `jsonb`,
contexto de usuário/IP e integração com migrations.

## Instalação

```bash
composer require mendesalexandre/laravel-pg-audit
php artisan vendor:publish --tag=pg-audit-migrations
php artisan vendor:publish --tag=pg-audit-config   # opcional
php artisan migrate
```

## Habilitando a auditoria

```php
Schema::create('posts', function (Blueprint $t) {
    $t->id();
    $t->string('title');
    $t->timestamps();
    $t->audit(except: ['updated_at']);
});
// $t->dropAudit() remove os triggers
```

Ou em tabelas existentes:

```bash
php artisan pg-audit:enable users orders --except=updated_at
php artisan pg-audit:disable orders
php artisan pg-audit:status
php artisan pg-audit:sync          # usa a chave "tables" da config
php artisan pg-audit:prune --days=365
```

## Schema e conexão separados

Os logs podem ficar em schemas diferentes (e em bancos diferentes, via conexão). Padrões em `config/pg-audit.php` (`connection`, `schema`, `table`); por tabela:

```php
// instala outro schema de auditoria (na migration)
AuditSchema::up(connection: 'tenant', schema: 'audit_financeiro', table: 'movimentos');

// usa esse schema só nesta tabela (a conexão é a do Schema::connection(...) da migration)
Schema::connection('tenant')->create('invoices', fn ($t) => $t->audit(schema: 'audit_financeiro'));
```

```bash
php artisan pg-audit:enable invoices --schema=audit_financeiro --connection=tenant
php artisan pg-audit:status --connection=tenant
```

```php
AuditLog::inSchema('audit_financeiro', 'tenant', 'movimentos')->forTable('invoices')->get();
PgAudit::actingAs($id, $fn, connection: 'tenant');
```

O trigger só grava no **mesmo banco** da tabela (limitação do PostgreSQL); "conexão separada" significa instalar o schema de auditoria em cada banco.
Para o middleware preencher o contexto em outras conexões, liste-as em `context_connections`.

## Quem fez a alteração

Registre o middleware **depois** da autenticação:

```php
// bootstrap/app.php
->withMiddleware(fn ($m) => $m->appendToGroup('web', \MendesAlexandre\PgAudit\Http\Middleware\SetAuditContext::class))
```

Fora de requests (jobs, comandos):

```php
PgAudit::actingAs($userId, fn () => $order->update([...]));
```

> O contexto fica na sessão do banco; não use com PgBouncer em modo *transaction*.

## Consultando

```php
use MendesAlexandre\PgAudit\Models\AuditLog;

AuditLog::forRecord('posts', 1)->latest('id')->get();
AuditLog::forTable('orders')->action('U', 'D')->with('user')->get();
```

No model: `use MendesAlexandre\PgAudit\Concerns\HasAuditLog;` → `$post->auditLogs`.

Cada log tem `action` (I/U/D/T), `old_values`, `new_values` (em updates só as colunas alteradas),
`user_id`, `ip_address`, `user_agent`, `url`, `transaction_id`, `created_at`.
Updates sem mudança real (ou só em colunas ignoradas) não geram log.

## Testes

```bash
docker run -d -p 5432:5432 -e POSTGRES_PASSWORD=secret postgres:18
composer install && vendor/bin/phpunit
```

## Notas

- `migrate:fresh` não apaga o schema `audit`; os logs antigos permanecem (em dev, use `DROP SCHEMA audit CASCADE`).
- `password` e `remember_token` são ignorados por padrão (`global_except`). Mudanças nessa lista só valem para triggers criados depois; rode `pg-audit:enable` de novo para atualizar os existentes.

## Licença

MIT
