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

## Pausar a auditoria (importações, seeders, correções em massa)

A pausa vale **só para a sessão atual do banco**: outras conexões e requests concorrentes continuam auditados.
Pausa e retomada ficam gravadas no log (eventos `P`/`R`, com usuário, IP, tabelas e motivo).

```php
PgAudit::withoutAuditing(function () {
    $this->importarProdutos();            // nada disso é auditado
}, reason: 'importação do ERP');

PgAudit::withoutAuditing(fn () => Order::query()->update([...]), tables: ['orders']);   // só estas tabelas
```

Retoma sozinho ao final, inclusive se o callback lançar exceção. Também existem `PgAudit::pause()`, `resume()` e `isPaused()`.
No modo `transaction` (PgBouncer) a pausa só vale dentro de transação: `withoutAuditing` abre uma sozinho; `pause()` solto
fora de transação lança exceção.

> **Instalações anteriores à pausa:** rode `php artisan pg-audit:upgrade` (recria a função do trigger e amplia a constraint de eventos). Os triggers existentes não mudam.

## Gravar o SQL (`capture_query`)

Opcional e **desligado por padrão**: grava o texto do SQL (`current_query()`) na coluna `query` (`consulta` em `pt_BR`).

```php
// config/pg-audit.php
'capture_query' => true,
'capture_query_tables' => ['orders'],   // vazio = todas as tabelas
'query_max_length' => 2000,             // truncado
```

Por tabela: `$t->audit(captureQuery: true)`, `protected bool $auditCaptureQuery = true;` no model ou `pg-audit:enable orders --capture-query`.
`pg-audit:status` mostra quais tabelas gravam a query.

- Com o PDO padrão do Laravel o texto sai parametrizado: `update "orders" set "status" = $1 where "id" = $2` (os valores reais
  continuam em `old_values`/`new_values`). Com `PDO::ATTR_EMULATE_PREPARES` ligado, os **valores vão embutidos no SQL** (e-mail, CPF...).
- Um `UPDATE` em massa repete a mesma query em cada linha afetada.

### `capture_query` e o PDO (`ATTR_EMULATE_PREPARES`)

**Não altere o PDO por causa deste recurso.** O pacote espera o padrão do Laravel (prepares reais,
`PDO::ATTR_EMULATE_PREPARES => false`), com o qual o SQL gravado é parametrizado e não carrega valores.

Se você ligar `ATTR_EMULATE_PREPARES => true`:

- o PDO monta o SQL no cliente e os **valores reais vão embutidos no texto** gravado (e-mail, CPF etc.);
- tipos como booleanos, inteiros e `jsonb` podem se comportar diferente;
- `old_values`/`new_values` não mudam, só o texto da query.

É comum ligar essa opção para usar **PgBouncer em modo transaction**, porque versões antigas não suportam prepared
statements nesse modo (o PgBouncer 1.21+ suporta, com `max_prepared_statements` configurado). Se o seu app já roda assim,
confira o conteúdo do log antes de ligar `capture_query` e restrinja `capture_query_tables` às tabelas sem dados pessoais.

Para saber **o que mudou**, use `old_values`/`new_values`; use `capture_query` só quando precisar da forma do SQL.
- `capture_query` cria a coluna: defina **antes** de instalar; em instalações antigas rode `pg-audit:upgrade`.
  `capture_query_tables` vale na hora de habilitar o trigger: rode `pg-audit:enable` de novo ao mudar a lista.

## Configuração dentro do model (array `$auditExcept`)

Em vez de repetir `--except` em cada comando ou migration, declare no model:

```php
class Order extends Model
{
    use HasAuditLog;

    protected array $auditExcept = ['updated_at', 'views'];   // colunas fora do log
    protected string $auditSchema = 'audit_vendas';
    protected string $auditPrefix = 'audit_';
}
```

```bash
php artisan pg-audit:enable "App\Models\Order"           # --except=x ainda soma às do model
```

```php
PgAudit::enableModel(Order::class);
```

Ou liste os models em `config/pg-audit.php` (`'models' => [Order::class]`) e rode `php artisan pg-audit:sync`.
Em migrations, o equivalente é `$t->audit(except: ['updated_at', 'views'])`.

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
| db_version (versão do PostgreSQL, ex. `18.6`) | `db_version` | `versao_banco` |
| query (SQL, opcional) | `query` | `consulta` |
| db_user / transaction_id | `db_user` / `transaction_id` | `usuario_banco` / `transacao_id` |
| created_at | `created_at` | `criado_em` |

```php
// config/pg-audit.php (ou PG_AUDIT_LOCALE=pt_BR no .env)
'locale' => 'pt_BR',
'columns' => ['row_id' => 'registro_id'],   // sobrescreve só uma coluna
```

A versão do servidor (`18.6`) é gravada em cada linha; desligue com `'capture_db_version' => false`.
Em instalações antigas, `php artisan pg-audit:upgrade` cria a coluna.

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

## Versão do esquema e histórico de DDL

Para interpretar o log depois de migrations (um `RENAME`/`ALTER TYPE` muda o formato do JSON antigo).

### Versão do esquema em cada linha (`schema_version` / `versao_schema`)
A versão fica numa tabela de 1 linha (`audit.schema_state`) que o trigger consulta; por isso vale **também para quem escreve
fora do app** (psql, outros sistemas) e não custa nenhuma ida ao banco por request. Ao fim do `php artisan migrate` o pacote grava
a última migration aplicada. Para usar o SHA do deploy: `PG_AUDIT_SCHEMA_VERSION=...` ou `php artisan pg-audit:version <valor>`.
Desligue a coluna com `'capture_schema_version' => false`.

```bash
php artisan pg-audit:version            # mostra versão do esquema, versão do PostgreSQL e quando mudou
php artisan pg-audit:version --record   # grava a configurada (ou a última migration)
```

### Histórico de DDL (`audit.schema_changes`)
Um *event trigger* registra cada `ALTER`/`CREATE`/`DROP` (inclui `DROP COLUMN`), com quem rodou (`db_user`, `user_id` do contexto), o texto
do comando, a migration em andamento, a versão do esquema e a **versão do PostgreSQL** (`pg_version`/`versao_pgsql`). Pega também DDL feito por DBA no psql.

```bash
php artisan pg-audit:ddl-log enable     # exige superusuário; senão imprime o SQL para o DBA rodar
php artisan pg-audit:ddl-log status|disable|sql
```
Não registra objetos do schema de auditoria, temporários ou de extensões. Em bancos gerenciados que não permitem event trigger,
use só a versão do esquema. Instalações antigas: `php artisan pg-audit:upgrade`.

### Valores reais na query só em algumas tabelas (`withLiteralQueries`)
`capture_query` grava a query como o PostgreSQL a recebe: com o PDO padrão, `update "orders" set "total" = $1 where "id" = $2`.
Os valores reais só entram no texto com `ATTR_EMULATE_PREPARES` (ver acima). Em vez de ligar globalmente, ligue **só ao gravar em
tabelas sem dados sensíveis**:

```php
PgAudit::withLiteralQueries(fn () => Order::find(1)->update(['status' => 'paid']));
// log: update "orders" set "status" = 'paid', "updated_at" = '2026-10-12 22:00:00' where "id" = 1
```
O atributo é ligado só na conexão de escrita e restaurado ao fim (mesmo com exceção); fora do bloco o SQL segue parametrizado.
Combine com `capture_query_tables` para que só as tabelas liberadas gravem a query. Atenção: com prepares emulados os valores aparecem
também no log do servidor e no `pg_stat_activity`, e as colunas em `except` **não** são mascaradas no texto; por isso use só em tabelas sem senha/token.
`pg-audit:status` avisa quando `capture_query` está ligado e o PDO emula prepares.

## O que foi alterado, com os valores reais

O texto gravado por `capture_query` vem parametrizado (`update "orders" set "status" = $1 where "id" = $2`): o PostgreSQL recebe o SQL
e os valores separados. Para ler **o que mudou**, use os dados do próprio log (que respeitam `except`), sem mexer no PDO:

```php
$log = AuditLog::forRecord('customers', 1)->latest('id')->first();

$log->diff;          // [['column' => 'lab_cpf', 'before' => '000...', 'after' => '111...']]  (insert: before = null; delete: after = null)
$log->readable_sql;  // update "customers" set "lab_cpf" = '111.222.333-44' where "id" = '1'
$log->summary;       // customers#1 U: lab_cpf: "000..." -> "111..."
```

`readable_sql` é para **leitura** (relatório, tela de histórico), montado de `old_values`/`new_values`; não execute. A coluna da chave é
`pg-audit.primary_key` (padrão `id`). Em `update`, `diff` e `readable_sql` trazem só as colunas que mudaram.

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
