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

## Nomes das colunas (idioma)

Colunas do log, com o padrão em inglês (`auditable_id`) e preset em português (`pt_BR`, `auditavel_id`):

| chave | `en` | `pt_BR` |
|---|---|---|
| schema | `auditable_schema` | `auditavel_schema` |
| table | `auditable_table` | `auditavel_tabela` |
| row_id | `auditable_id` | `auditavel_id` |
| event (I/U/D/T) | `event` | `evento` |
| old_values / new_values | `old_values` / `new_values` | `valores_antigos` / `valores_novos` |
| user_id | `user_id` | `usuario_id` |
| ip_address / user_agent / url | `ip_address` / `user_agent` / `url` | `ip` / `navegador` / `url` |
| db_user / transaction_id | `db_user` / `transaction_id` | `usuario_banco` / `transacao_id` |
| created_at | `created_at` | `criado_em` |

```php
// config/pg-audit.php (ou PG_AUDIT_LOCALE=pt_BR no .env)
'locale' => 'pt_BR',
'columns' => ['row_id' => 'registro_id'],   // sobrescreve só uma coluna
```

Defina **antes** de instalar. Se mudar depois, gere a migration que renomeia as colunas existentes (mantém o histórico,
cobre as tabelas por prefixo e recria a função do trigger):

```bash
php artisan pg-audit:rename-columns --from=en --to=pt_BR
```

No model, use os nomes do idioma escolhido: `$log->auditavel_id`, `$log->valores_novos`, `$log->criado_em`.
`$log->action_name` devolve `created|updated|deleted|truncated` em qualquer idioma.

## Uma tabela de log por tabela auditada (prefixo)

Por padrão todas as tabelas gravam em `audit.logged_actions`. Com `prefix`, cada tabela auditada ganha a sua própria
tabela de log (`prefixo + nome`), criada automaticamente no schema escolhido:

```php
$t->audit(schema: 'audit_vendas', prefix: 'audit_');   // orders -> audit_vendas.audit_orders
```

Ou globalmente com `'table_prefix' => 'audit_'` na config. No model, informe onde está o log:

```php
use HasAuditLog;
protected string $auditSchema = 'audit_vendas';
protected string $auditPrefix = 'audit_';   // $order->auditLogs
```

## Gerando migrations

```bash
# 1) instala schema + tabela de log + função (use --schema/--table/--connection para algo diferente do padrão)
php artisan pg-audit:install --schema=audit_vendas

# 2) para tabelas que já existem: gera a migration que liga os triggers
php artisan pg-audit:make-migration orders order_items --schema=audit_vendas --prefix=audit_ --except=updated_at
```

Para tabelas novas basta `$t->audit(...)` dentro do `Schema::create`. Os triggers capturam `OLD`/`NEW` sozinhos: você só informa
as **tabelas** (e, se quiser, colunas a ignorar); `old_values`/`new_values` são preenchidos automaticamente.

## Quem fez a alteração

Registre o middleware **depois** da autenticação:

```php
// bootstrap/app.php
->withMiddleware(function ($m) {
    $m->appendToGroup('web', \MendesAlexandre\PgAudit\Http\Middleware\SetAuditContext::class);
    $m->appendToGroup('api', \MendesAlexandre\PgAudit\Http\Middleware\SetAuditContext::class); // rotas api/* também
})
```

Fora de requests (jobs, comandos):

```php
PgAudit::actingAs($userId, fn () => $order->update([...]));
```

### PgBouncer (modo `transaction`), Octane e réplicas

- **PgBouncer em modo transaction:** com o contexto na *sessão* (padrão), a conexão é compartilhada entre clientes e a
  alteração pode ser atribuída a **outra pessoa**, sem erro. Use `PG_AUDIT_CONTEXT_MODE=transaction`: o contexto vira
  `set_config(..., true)` (local da transação) a cada `BEGIN`, e o middleware envolve o request em uma transação
  (`wrap_request_in_transaction`). Escritas fora de transação ficam **sem usuário** (nunca com o usuário errado);
  em jobs/comandos use `PgAudit::actingAs($id, fn () => ...)`, que abre a transação sozinho nesse modo.
- **Leitura/escrita separadas (`read`/`write`):** o contexto sempre é enviado pela conexão de escrita, onde o trigger roda.
- **Octane/filas:** o gerenciador é `scoped` (zerado a cada request/job) e o middleware limpa o contexto no `terminate`.
- **Octane (testado):** o Octane atende cada request com um *clone* da aplicação, então tudo que é registrado no `boot()` e
  precisa do estado do request deve resolver o `AuditManager` pelo container corrente (`app(AuditManager::class)`), não por
  `$this->app` do provider (que é a aplicação-base). O pacote já faz isso no listener de `TransactionBeginning`; se você
  estender o pacote ou registrar listeners próprios, siga a mesma regra. Validado com Octane + FrankenPHP (8 workers),
  PgBouncer em `pool_mode = transaction` e `PG_AUDIT_CONTEXT_MODE=transaction`: em 3 rodadas de 900 requests com 60 em
  paralelo, todos os pedidos ficaram com o usuário e a URL corretos e nenhuma escrita ficou sem contexto. Com Octane não use
  o modo `session` junto de PgBouncer em modo transaction. Teste de regressão: `test_transaction_mode_works_with_octane_style_cloned_container`.
- **Custo:** o middleware faz 1 ida ao banco ao entrar e 1 ao sair. Se seus `GET` nunca gravam, `'skip_methods' => ['GET', 'HEAD', 'OPTIONS']` elimina isso.
- **IP atrás de proxy (nginx/HAProxy):** configure `TrustProxies` no app, senão o IP gravado é o do proxy.

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
