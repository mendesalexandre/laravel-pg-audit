<?php

namespace MendesAlexandre\PgAudit\Tests;

use Illuminate\Container\Container;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MendesAlexandre\PgAudit\AuditManager;
use MendesAlexandre\PgAudit\Database\AuditSchema;
use MendesAlexandre\PgAudit\Facades\PgAudit;
use MendesAlexandre\PgAudit\Models\AuditLog;
use MendesAlexandre\PgAudit\PgAuditServiceProvider;
use Orchestra\Testbench\TestCase;

class AuditTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PgAuditServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', 5432),
            'database' => env('DB_DATABASE', 'postgres'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', 'secret'),
            'schema' => 'public',
        ]);
        $app['config']->set('database.connections.pgsql_other', $app['config']->get('database.connections.pgsql'));
        // Leitura aponta para uma porta morta: qualquer set_config pela réplica falharia.
        $app['config']->set('database.connections.pgsql_rw', array_merge($app['config']->get('database.connections.pgsql'), [
            'read' => ['port' => 1],
            'write' => ['port' => env('DB_PORT', 5432)],
        ]));
    }

    protected function setUp(): void
    {
        parent::setUp();
        AuditSchema::down();
        AuditSchema::up();
        Schema::dropIfExists('posts');
        Schema::create('posts', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->timestamp('updated_at')->nullable();
            $t->audit(except: ['updated_at']);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('posts');
        AuditSchema::down();
        parent::tearDown();
    }

    public function test_insert_update_delete_are_logged(): void
    {
        PgAudit::actingAs(42, function () {
            $id = DB::table('posts')->insertGetId(['title' => 'a']);
            DB::table('posts')->where('id', $id)->update(['title' => 'b']);
            DB::table('posts')->where('id', $id)->update(['updated_at' => now()]); // ignorado
            DB::table('posts')->where('id', $id)->update(['title' => 'b']); // sem mudança
            DB::table('posts')->where('id', $id)->delete();
        });

        $logs = AuditLog::orderBy('id')->get();
        $this->assertSame(['I', 'U', 'D'], $logs->pluck('event')->all());
        $this->assertSame(['title' => 'a'], $logs[1]->old_values);
        $this->assertSame(['title' => 'b'], $logs[1]->new_values);
        $this->assertSame('42', $logs[0]->user_id);
        $this->assertSame('1', $logs[0]->auditable_id);
        $this->assertArrayNotHasKey('updated_at', $logs[2]->old_values);
    }

    public function test_context_restored_and_truncate_logged(): void
    {
        PgAudit::actingAs(1, fn () => null);
        DB::table('posts')->insert(['title' => 'x']);
        DB::table('posts')->truncate();

        $this->assertNull(AuditLog::where('event', 'I')->first()->user_id);
        $this->assertSame(1, AuditLog::where('event', 'T')->count());
    }

    public function test_status_and_disable(): void
    {
        $this->assertSame('public.posts', PgAudit::audited()[0]['table']);
        $except = explode(',', PgAudit::audited()[0]['except']);
        sort($except);
        $this->assertSame(['password', 'remember_token', 'updated_at'], $except);

        PgAudit::disable('posts');
        $this->assertSame([], PgAudit::audited());
    }

    public function test_separate_schema_per_table(): void
    {
        AuditSchema::up(null, 'audit_fin', 'movimentos');
        Schema::dropIfExists('invoices');
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->integer('amount');
            $t->audit(schema: 'audit_fin');
        });

        DB::table('invoices')->insert(['amount' => 10]);
        DB::table('posts')->insert(['title' => 'p']);

        $this->assertSame(1, AuditLog::inSchema('audit_fin', null, 'movimentos')->count());
        $this->assertSame(1, AuditLog::count());           // schema padrão só tem o post
        $this->assertSame('invoices', AuditLog::inSchema('audit_fin', null, 'movimentos')->first()->auditable_table);
        $this->assertContains('audit_fin', array_column(PgAudit::audited(), 'audit_schema'));

        Schema::dropIfExists('invoices');
        AuditSchema::down(null, 'audit_fin');
    }

    public function test_explicit_connection_argument(): void
    {
        PgAudit::enable('public.posts', connection: 'pgsql');
        DB::table('posts')->insert(['title' => 'x']);
        $this->assertSame(1, AuditLog::on('pgsql')->count());
    }

    public function test_prefix_creates_log_table_per_audited_table(): void
    {
        AuditSchema::up(null, 'audit_vendas');
        Schema::dropIfExists('orders');
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->string('status')->default('new');
            $t->audit(schema: 'audit_vendas', prefix: 'audit_');
        });

        $id = DB::table('orders')->insertGetId([]);
        DB::table('orders')->where('id', $id)->update(['status' => 'paid']);

        $rows = AuditLog::inSchema('audit_vendas', null, 'audit_orders')->orderBy('id')->get();
        $this->assertSame(['I', 'U'], $rows->pluck('event')->all());
        $this->assertSame(0, AuditLog::inSchema('audit_vendas')->count()); // tabela padrão não recebe
        $this->assertSame('audit_orders', collect(PgAudit::audited())->firstWhere('table', 'public.orders')['log_table']);

        // trait HasAuditLog resolvendo schema + prefixo
        $model = new class extends \Illuminate\Database\Eloquent\Model {
            use \MendesAlexandre\PgAudit\Concerns\HasAuditLog;

            protected $table = 'orders';

            protected string $auditSchema = 'audit_vendas';

            protected string $auditPrefix = 'audit_';
        };
        $this->assertSame(2, $model->newQuery()->find($id)->auditLogs()->count());

        Schema::dropIfExists('orders');
        AuditSchema::down(null, 'audit_vendas');
    }

    public function test_make_migration_commands_generate_files(): void
    {
        $dir = database_path('migrations');
        $before = glob($dir.'/*') ?: [];

        $this->artisan('pg-audit:install', ['--schema' => 'audit_vendas'])->assertSuccessful();
        $this->artisan('pg-audit:make-migration', ['tables' => ['orders', 'order_items'], '--schema' => 'audit_vendas', '--prefix' => 'audit_', '--except' => ['updated_at']])->assertSuccessful();

        $new = array_diff(glob($dir.'/*') ?: [], $before);
        $this->assertCount(2, $new);
        foreach ($new as $file) {
            $this->assertSame(0, strlen(shell_exec('php -l '.escapeshellarg($file).' 2>&1 | grep -v "^No syntax"') ?? ''));
            $out[] = file_get_contents($file);
            unlink($file);
        }
        $all = implode("\n", $out);
        $this->assertStringContainsString("AuditSchema::up(schema: 'audit_vendas')", $all);
        $this->assertStringContainsString("schema: 'audit_vendas', prefix: 'audit_'", $all);
    }

    public function test_portuguese_preset_and_single_column_override(): void
    {
        AuditSchema::down();
        config(['pg-audit.locale' => 'pt_BR', 'pg-audit.columns' => ['row_id' => 'registro_id']]);
        AuditSchema::up();
        Schema::dropIfExists('posts');
        Schema::create('posts', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->audit();
        });

        PgAudit::actingAs(7, function () {
            DB::table('posts')->insert(['title' => 'a']);
            DB::table('posts')->update(['title' => 'b']);
        });

        $cols = collect(DB::select("select column_name from information_schema.columns where table_schema='audit' and table_name='logged_actions'"))->pluck('column_name');
        $this->assertTrue($cols->contains('registro_id'));
        $this->assertTrue($cols->contains('evento'));
        $this->assertFalse($cols->contains('auditable_id'));

        $log = AuditLog::forRecord('posts', 1)->action('U')->first();
        $this->assertSame('registro_id', \MendesAlexandre\PgAudit\Support\Columns::get('row_id'));
        $this->assertSame('1', $log->registro_id);
        $this->assertSame('posts', $log->auditavel_tabela);
        $this->assertSame(['title' => 'b'], $log->valores_novos);
        $this->assertSame('7', $log->usuario_id);
        $this->assertSame('updated', $log->action_name);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $log->criado_em);
    }

    public function test_unknown_column_key_is_rejected(): void
    {
        config(['pg-audit.columns' => ['nope' => 'x']]);
        $this->expectException(\InvalidArgumentException::class);
        \MendesAlexandre\PgAudit\Support\Columns::all();
    }

    public function test_rename_columns_migration_keeps_history_and_triggers_working(): void
    {
        DB::table('posts')->insert(['title' => 'antes']);

        $from = \MendesAlexandre\PgAudit\Support\Columns::for('en');
        $to = \MendesAlexandre\PgAudit\Support\Columns::for('pt_BR');
        AuditSchema::renameColumns($from, $to);
        config(['pg-audit.locale' => 'pt_BR']);

        DB::table('posts')->insert(['title' => 'depois']);

        $logs = AuditLog::orderBy('id')->get();
        $this->assertCount(2, $logs);                       // histórico preservado
        $this->assertSame(['I', 'I'], $logs->pluck('evento')->all());
        $this->assertSame('depois', $logs[1]->valores_novos['title']);

        AuditSchema::renameColumns($to, $from);            // down()
        config(['pg-audit.locale' => 'en']);
        $this->assertSame(2, AuditLog::where('event', 'I')->count());
    }

    public function test_rename_columns_command_generates_valid_migration(): void
    {
        $dir = database_path('migrations');
        $before = glob($dir.'/*') ?: [];

        $this->artisan('pg-audit:rename-columns', ['--from' => 'en', '--to' => 'pt_BR'])->assertSuccessful();

        $new = array_values(array_diff(glob($dir.'/*') ?: [], $before));
        $this->assertCount(1, $new);
        $code = file_get_contents($new[0]);
        unlink($new[0]);
        $this->assertStringContainsString("'created_at' => 'criado_em'", $code);
        $this->assertStringContainsString('AuditSchema::renameColumns($this->from, $this->to)', $code);
    }

    public function test_context_uses_write_connection_when_read_write_split(): void
    {
        PgAudit::setContext(['user_id' => '3', 'ip_address' => '10.0.0.1', 'url' => 'http://x/y'], 'pgsql_rw');
        DB::connection('pgsql_rw')->table('posts')->insert(['title' => 'rw']);

        $log = AuditLog::first();
        $this->assertSame('3', $log->user_id);
        $this->assertSame('10.0.0.1', $log->ip_address);
        $this->assertSame('http://x/y', $log->url);
    }

    public function test_transaction_mode_is_scoped_to_the_transaction(): void
    {
        config(['pg-audit.context_mode' => 'transaction']);

        PgAudit::setContext(['user_id' => '5']);
        DB::table('posts')->insert(['title' => 'fora']);                       // sem transação: sem usuário
        DB::transaction(fn () => DB::table('posts')->insert(['title' => 'dentro']));
        // nada vaza para a sessão (o PgBouncer pode entregar a mesma sessão a outro cliente)
        $this->assertEmpty(DB::selectOne("select current_setting('audit.user_id', true) as v")->v);

        PgAudit::actingAs(9, fn () => DB::table('posts')->insert(['title' => 'acting']));

        $this->assertSame([null, '5', '9'], AuditLog::orderBy('id')->pluck('user_id')->all());
        $this->assertEmpty(DB::selectOne("select current_setting('audit.user_id', true) as v")->v);
    }

    public function test_transaction_mode_works_with_octane_style_cloned_container(): void
    {
        config(['pg-audit.context_mode' => 'transaction']);

        // Octane atende cada request com um clone da aplicação (Container::setInstance) e zera os scoped.
        // O listener de TransactionBeginning foi registrado no boot, na aplicação-base: ele precisa usar
        // o AuditManager do container corrente, e não o do $this->app do provider.
        $sandbox = clone $this->app;
        Container::setInstance($sandbox);
        $sandbox->forgetScopedInstances();

        try {
            $sandbox->make(AuditManager::class)->setContext(['user_id' => '11', 'url' => 'http://octane/x']);
            DB::transaction(fn () => DB::table('posts')->insert(['title' => 'octane']));
        } finally {
            Container::setInstance($this->app);
        }

        $log = AuditLog::first();
        $this->assertSame('11', $log->user_id);
        $this->assertSame('http://octane/x', $log->url);
    }

    public function test_log_exposes_readable_changes_and_sql_with_real_values(): void
    {
        $id = DB::table('posts')->insertGetId(['title' => "O'Brien"]);
        DB::table('posts')->where('id', $id)->update(['title' => 'Novo título']);
        DB::table('posts')->where('id', $id)->delete();

        [$insert, $update, $delete] = AuditLog::orderBy('id')->get()->all();

        $this->assertSame([['column' => 'title', 'before' => "O'Brien", 'after' => 'Novo título']], $update->diff);
        $this->assertSame("update \"posts\" set \"title\" = 'Novo título' where \"id\" = '{$id}'", $update->readable_sql);
        $this->assertStringContainsString("values (", $insert->readable_sql);
        $this->assertStringContainsString("'O''Brien'", $insert->readable_sql);     // aspas escapadas
        $this->assertSame("delete from \"posts\" where \"id\" = '{$id}'", $delete->readable_sql);
        $this->assertSame("posts#{$id} U: title: \"O'Brien\" -> \"Novo título\"", $update->summary);
        $this->assertStringNotContainsString('$1', $update->readable_sql);
    }

    public function test_schema_version_is_stored_in_each_log_row_for_any_writer(): void
    {
        $manager = app(AuditManager::class);
        $manager->recordSchemaVersion('v1');
        DB::table('posts')->insert(['title' => 'a']);
        $manager->recordSchemaVersion('v2');
        DB::table('posts')->insert(['title' => 'b']);

        $this->assertSame(['v1', 'v2'], AuditLog::orderBy('id')->pluck('schema_version')->all());
        $this->assertSame('v2', $manager->schemaState()->version);
        $this->assertNotEmpty($manager->schemaState()->pg_version);
    }

    public function test_schema_version_uses_config_value_when_not_given(): void
    {
        config(['pg-audit.schema_version' => 'deploy-abc123']);
        $this->assertSame('deploy-abc123', app(AuditManager::class)->recordSchemaVersion());
    }

    public function test_migration_name_is_extracted_from_anonymous_migration_class(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'm').'.php';
        $dir = sys_get_temp_dir().'/mig'.uniqid();
        mkdir($dir);
        $file = $dir.'/2026_10_12_120000_add_cpf_to_users.php';
        file_put_contents($file, '<?php return new class { };');
        $migration = require $file;

        $this->assertSame('2026_10_12_120000_add_cpf_to_users', AuditManager::migrationName($migration));
        unlink($file);
        rmdir($dir);
    }

    public function test_ddl_log_records_alter_and_drop_with_query_migration_and_versions(): void
    {
        AuditSchema::enableDdlLog();
        $this->assertTrue(AuditSchema::ddlLogEnabled());

        try {
            $manager = app(AuditManager::class);
            $manager->recordSchemaVersion('v7');
            $manager->setMigration('2026_10_12_120000_add_cpf');
            PgAudit::setContext(['user_id' => '8']);

            Schema::table('posts', fn (Blueprint $t) => $t->string('cpf')->nullable());
            Schema::table('posts', fn (Blueprint $t) => $t->dropColumn('cpf'));
            Schema::create('ddl_tmp', fn (Blueprint $t) => $t->id());
            Schema::drop('ddl_tmp');

            $rows = DB::table('audit.schema_changes')->orderBy('id')->get();
            $tags = $rows->pluck('command_tag')->all();
            $this->assertContains('ALTER TABLE', $tags);
            $this->assertContains('CREATE TABLE', $tags);
            $this->assertContains('DROP TABLE', $tags);

            $alter = $rows->firstWhere('command_tag', 'ALTER TABLE');
            $this->assertSame('public.posts', $alter->object_identity);
            $this->assertStringContainsStringIgnoringCase('add column', $alter->query);
            $this->assertSame('2026_10_12_120000_add_cpf', $alter->migration);
            $this->assertSame('v7', $alter->schema_version);
            $this->assertSame('8', $alter->user_id);
            $this->assertNotEmpty($alter->pg_version);
            $this->assertNotNull($rows->first(fn ($r) => $r->command_tag === 'ALTER TABLE' && str_contains($r->query, 'drop column')));

            // nada do próprio schema de auditoria entra no histórico
            $this->assertCount(0, $rows->where('schema_name', 'audit'));
        } finally {
            AuditSchema::disableDdlLog();
            Schema::dropIfExists('ddl_tmp');
        }

        $this->assertFalse(AuditSchema::ddlLogEnabled());
    }

    public function test_upgrade_adds_schema_version_column_and_state_table_to_old_installation(): void
    {
        // simula instalação antiga: sem a coluna schema_version e sem a tabela de estado
        DB::unprepared('ALTER TABLE audit.logged_actions DROP COLUMN schema_version');
        DB::unprepared('DROP TABLE audit.schema_state');

        AuditSchema::refreshFunction();
        app(AuditManager::class)->recordSchemaVersion('depois-do-upgrade');
        DB::table('posts')->insert(['title' => 'x']);

        $this->assertSame('depois-do-upgrade', AuditLog::first()->schema_version);
    }

    public function test_ddl_table_and_columns_follow_locale(): void
    {
        config(['pg-audit.locale' => 'pt_BR']);
        $cols = \MendesAlexandre\PgAudit\Support\Columns::ddl();
        $this->assertSame('versao_pgsql', $cols['pg_version']);
        $this->assertSame('executado_em', $cols['executed_at']);
        $this->assertSame('versao_schema', \MendesAlexandre\PgAudit\Support\Columns::get('schema_version'));
    }

    public function test_with_literal_queries_embeds_values_only_inside_the_callback(): void
    {
        AuditSchema::down();
        config(['pg-audit.capture_query' => true, 'pg-audit.capture_query_tables' => [], 'pg-audit.query_max_length' => 300]);
        AuditSchema::up();
        Schema::dropIfExists('posts');
        Schema::create('posts', fn (Blueprint $t) => tap($t, function ($t) {
            $t->id();
            $t->string('title');
            $t->audit();
        }));
        $manager = app(AuditManager::class);
        $this->assertFalse($manager->emulatesPrepares());

        $manager->withLiteralQueries(function () use ($manager) {
            $this->assertTrue($manager->emulatesPrepares());
            DB::table('posts')->insert(['title' => "O'Brien"]);
        });
        DB::table('posts')->insert(['title' => 'segredo']);          // fora do bloco: parametrizado
        $this->assertFalse($manager->emulatesPrepares());              // restaurado

        [$dentro, $fora] = AuditLog::orderBy('id')->pluck('query')->all();
        $this->assertSame('insert into "posts" ("title") values (\'O\'\'Brien\')', $dentro);
        $this->assertSame('insert into "posts" ("title") values ($1)', $fora);
        $this->assertStringNotContainsString('segredo', $fora);
    }

    public function test_status_warns_when_capture_query_meets_emulated_prepares(): void
    {
        AuditSchema::down();
        config(['pg-audit.capture_query' => true]);
        AuditSchema::up();
        Schema::dropIfExists('posts');
        Schema::create('posts', fn (Blueprint $t) => tap($t, function ($t) {
            $t->id();
            $t->string('title');
            $t->audit(except: ['title'], captureQuery: true);
        }));

        $this->artisan('pg-audit:status')->doesntExpectOutputToContain('ATTR_EMULATE_PREPARES')->assertSuccessful();

        app(AuditManager::class)->withLiteralQueries(function () {
            $this->artisan('pg-audit:status')
                ->expectsOutputToContain('ATTR_EMULATE_PREPARES está ligado')
                ->expectsOutputToContain('public.posts')
                ->assertSuccessful();
        });
    }

    public function test_restore_reinserts_a_deleted_row_and_dry_run_writes_nothing(): void
    {
        $id = DB::table('posts')->insertGetId(['title' => "O'Brien"]);
        DB::table('posts')->where('id', $id)->delete();
        $log = AuditLog::deletions()->latest('id')->first();

        $dry = $log->restore(dryRun: true);
        $this->assertSame("insert into \"public\".\"posts\" (\"id\", \"title\") values ({$id}, 'O''Brien')", $dry['sql']);
        $this->assertFalse($dry['done']);
        $this->assertSame(0, DB::table('posts')->count());

        $log->restore();
        $this->assertSame("O'Brien", DB::table('posts')->find($id)->title);       // mesmo id e dados

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $log->restore();                                                          // já existe: não duplica
    }

    public function test_restore_asks_for_columns_that_were_never_logged(): void
    {
        Schema::dropIfExists('accounts');
        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('senha');                         // NOT NULL e fora do log
            $t->audit(except: ['senha']);
        });
        $id = DB::table('accounts')->insertGetId(['name' => 'ana', 'senha' => 'HASH-ORIGINAL']);
        DB::table('accounts')->where('id', $id)->delete();
        $log = AuditLog::forTable("accounts")->deletions()->first();

        $this->assertArrayNotHasKey('senha', $log->valores_antigos ?? $log->old_values);
        try {
            $log->restore();
            $this->fail('senha é NOT NULL e não está no log');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('senha', $e->getMessage());
        }
        $this->assertSame(0, DB::table('accounts')->count());

        $log->restore(['senha' => 'NOVO-HASH']);
        $this->assertSame('NOVO-HASH', DB::table('accounts')->find($id)->senha);
        Schema::dropIfExists('accounts');
    }

    public function test_restore_update_puts_old_values_back_and_insert_is_not_restorable(): void
    {
        $id = DB::table('posts')->insertGetId(['title' => 'antes']);
        DB::table('posts')->where('id', $id)->update(['title' => 'depois']);

        AuditLog::action('U')->latest('id')->first()->restore();
        $this->assertSame('antes', DB::table('posts')->find($id)->title);

        $this->expectException(\LogicException::class);
        AuditLog::action('I')->first()->restore();
    }

    public function test_restore_transaction_brings_back_cascade_parent_and_children_atomically(): void
    {
        Schema::dropIfExists('filhos');
        Schema::dropIfExists('pais');
        Schema::create('pais', function (Blueprint $t) { $t->id(); $t->string('nome'); $t->audit(); });
        Schema::create('filhos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pai_id')->constrained('pais')->cascadeOnDelete();
            $t->string('item');
            $t->audit();
        });
        $pai = DB::table('pais')->insertGetId(['nome' => 'Maria']);
        DB::table('filhos')->insert([['pai_id' => $pai, 'item' => 'a'], ['pai_id' => $pai, 'item' => 'b']]);

        PgAudit::actingAs(42, fn () => DB::table('pais')->where('id', $pai)->delete());      // apaga o pai e, em cascata, os filhos
        $tx = AuditLog::deletions()->latest('id')->first()->transaction_id;
        $this->assertSame(0, DB::table('filhos')->count());

        $plan = app(\MendesAlexandre\PgAudit\Support\Restorer::class)->restoreTransaction($tx, dryRun: true);
        $this->assertCount(3, $plan);
        $this->assertSame(0, DB::table('pais')->count());

        $done = app(\MendesAlexandre\PgAudit\Support\Restorer::class)->restoreTransaction($tx);
        $this->assertCount(3, $done);
        $this->assertSame(1, DB::table('pais')->count());
        $this->assertSame(['a', 'b'], DB::table('filhos')->orderBy('id')->pluck('item')->all());

        // a restauração também é auditada
        $this->assertSame(3, AuditLog::action('I')->whereIn('auditable_table', ['pais', 'filhos'])->count() - 3);
        Schema::dropIfExists('filhos');
        Schema::dropIfExists('pais');
    }

    public function test_restore_transaction_retries_foreign_key_order_and_rolls_back_all_on_failure(): void
    {
        Schema::dropIfExists('filhos');
        Schema::dropIfExists('pais');
        Schema::create('pais', function (Blueprint $t) { $t->id(); $t->string('nome'); $t->audit(); });
        Schema::create('filhos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pai_id')->constrained('pais');
            $t->string('item');
            $t->audit();
        });
        $pai = DB::table('pais')->insertGetId(['nome' => 'Maria']);
        $filho = DB::table('filhos')->insertGetId(['pai_id' => $pai, 'item' => 'a']);

        // filho é apagado ANTES do pai: o log está na ordem filho, pai; restaurar na ordem do log também funciona
        DB::transaction(function () use ($pai, $filho) {
            DB::table('filhos')->where('id', $filho)->delete();
            DB::table('pais')->where('id', $pai)->delete();
        });
        $tx = AuditLog::deletions()->latest('id')->first()->transaction_id;
        $restorer = app(\MendesAlexandre\PgAudit\Support\Restorer::class);

        // conflito no meio: o pai já foi recriado por outra pessoa -> nada é restaurado (tudo-ou-nada)
        DB::table('pais')->insert(['id' => $pai, 'nome' => 'recriado']);
        try {
            $restorer->restoreTransaction($tx);
            $this->fail('deveria falhar por chave duplicada');
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
        }
        $this->assertSame(0, DB::table('filhos')->count());

        DB::table('pais')->where('id', $pai)->delete();
        $restorer->restoreTransaction($tx);
        $this->assertSame(1, DB::table('filhos')->count());
        Schema::dropIfExists('filhos');
        Schema::dropIfExists('pais');
    }

    public function test_deleted_and_restore_commands(): void
    {
        $id = DB::table('posts')->insertGetId(['title' => 'apagado sem querer']);
        PgAudit::actingAs(7, fn () => DB::table('posts')->where('id', $id)->delete());
        $logId = AuditLog::deletions()->latest('id')->first()->id;

        $this->artisan('pg-audit:deleted', ['--table' => 'posts', '--user' => 7])->expectsOutputToContain('apagado sem querer')->assertSuccessful();
        $this->artisan('pg-audit:restore', ['id' => $logId, '--dry-run' => true])->expectsOutputToContain('insert into "public"."posts"')->assertSuccessful();
        $this->assertSame(0, DB::table('posts')->count());

        $this->artisan('pg-audit:restore', ['--table' => 'posts', '--record' => $id, '--force' => true, '--user' => 99])->assertSuccessful();
        $this->assertSame('apagado sem querer', DB::table('posts')->find($id)->title);
        $this->assertSame('99', AuditLog::action('I')->latest('id')->first()->user_id);   // quem restaurou fica registrado
    }

    public function test_model_trait_embeds_values_without_wrapping_and_keeps_sensitive_writes_parameterized(): void
    {
        AuditSchema::down();
        config(['pg-audit.capture_query' => true, 'pg-audit.query_max_length' => 300]);
        AuditSchema::up();
        Schema::dropIfExists('lit_items');
        Schema::create('lit_items', function (Blueprint $t) {
            $t->id();
            $t->string('nome');
            $t->string('senha')->nullable();
            $t->audit(except: ['senha'], captureQuery: true);
        });
        $manager = app(AuditManager::class);

        $model = new class extends \Illuminate\Database\Eloquent\Model {
            use \MendesAlexandre\PgAudit\Concerns\WithLiteralQueries;

            protected $table = 'lit_items';
            public $timestamps = false;
            protected $guarded = [];
            protected array $auditExcept = ['senha'];
        };

        $item = $model->newQuery()->create(['nome' => "O'Brien"]);
        $item->update(['nome' => 'Novo nome']);
        $this->assertFalse($manager->emulatesPrepares());                  // restaurado depois de cada gravação

        $item->update(['nome' => 'Com senha', 'senha' => 'HASH-SECRETO']);   // inclui coluna sensível: continua parametrizado
        $item->delete();

        $q = AuditLog::forTable('lit_items')->orderBy('id')->pluck('query')->all();
        $this->assertSame('insert into "lit_items" ("nome") values (\'O\'\'Brien\') returning "id"', $q[0]);
        $this->assertSame('update "lit_items" set "nome" = \'Novo nome\' where "id" = 1', $q[1]);
        $this->assertSame('update "lit_items" set "nome" = $1, "senha" = $2 where "id" = $3', $q[2]);
        $this->assertSame('delete from "lit_items" where "id" = 1', $q[3]);
        $this->assertStringNotContainsString('HASH-SECRETO', implode(' ', $q));

        // fora do model (Query Builder), o PDO segue padrão
        DB::table('lit_items')->insert(['nome' => 'qb']);
        $this->assertStringContainsString('$1', AuditLog::forTable('lit_items')->orderByDesc('id')->first()->query);
        Schema::dropIfExists('lit_items');
    }

    public function test_make_migration_generates_executable_schema_table_calls(): void
    {
        $dir = database_path('migrations');
        $before = glob($dir.'/*') ?: [];
        $this->artisan('pg-audit:make-migration', ['tables' => ['posts'], '--connection' => 'pgsql'])->assertSuccessful();
        $this->artisan('pg-audit:make-migration', ['tables' => ['posts']])->assertSuccessful();

        $files = array_values(array_diff(glob($dir.'/*') ?: [], $before));
        $this->assertCount(2, $files);
        foreach ($files as $file) {
            $code = file_get_contents($file);
            $this->assertStringNotContainsString('Schema->', $code);
            $this->assertMatchesRegularExpression("/Schema::(connection\\('pgsql'\\)->)?table\\('posts'/", $code);

            // executa de verdade: reaplica o trigger e desfaz
            $migration = require $file;
            $migration->up();
            $migration->down();
            unlink($file);
        }
        $this->assertSame([], PgAudit::audited());
    }

    public function test_without_auditing_pauses_only_this_session_and_logs_who(): void
    {
        PgAudit::setContext(['user_id' => '8']);

        PgAudit::withoutAuditing(function () {
            DB::table('posts')->insert(['title' => 'importado']);                     // não auditado
            DB::connection('pgsql_other')->table('posts')->insert(['title' => 'outra sessão']); // continua auditado
        }, reason: 'importação');

        DB::table('posts')->insert(['title' => 'depois']);                            // retomado

        $logs = AuditLog::orderBy('id')->get();
        $this->assertSame(['P', 'I', 'R', 'I'], $logs->pluck('event')->all());
        $this->assertSame('outra sessão', $logs[1]->new_values['title']);
        $this->assertSame('depois', $logs[3]->new_values['title']);
        $this->assertEquals(['tables' => '*', 'reason' => 'importação'], $logs[0]->new_values);
        $this->assertSame('8', $logs[0]->user_id);
        $this->assertSame('paused', $logs[0]->action_name);
        $this->assertFalse(PgAudit::isPaused());
    }

    public function test_pause_specific_tables_and_resume_after_exception(): void
    {
        Schema::dropIfExists('notes');
        Schema::create('notes', function (Blueprint $t) {
            $t->id();
            $t->string('body');
            $t->audit();
        });

        try {
            PgAudit::withoutAuditing(function () {
                DB::table('posts')->insert(['title' => 'pausada']);
                DB::table('notes')->insert(['body' => 'auditada']);
                throw new \RuntimeException('falhou');
            }, tables: ['posts']);
        } catch (\RuntimeException) {
        }

        DB::table('posts')->insert(['title' => 'retomada']);

        $this->assertFalse(PgAudit::isPaused());
        $this->assertSame(['P', 'I', 'R', 'I'], AuditLog::orderBy('id')->pluck('event')->all());
        $this->assertSame(['notes', 'posts'], AuditLog::where('event', 'I')->orderBy('id')->get()->map(fn ($l) => $l->auditable_table)->sort()->values()->all());
        Schema::dropIfExists('notes');
    }

    public function test_pause_in_transaction_mode_requires_transaction_and_does_not_leak(): void
    {
        config(['pg-audit.context_mode' => 'transaction']);

        try {
            PgAudit::pause();
            $this->fail('deveria exigir transação');
        } catch (\LogicException) {
        }

        PgAudit::withoutAuditing(fn () => DB::table('posts')->insert(['title' => 'x']));
        DB::transaction(fn () => DB::table('posts')->insert(['title' => 'y']));

        $this->assertSame(['P', 'R', 'I'], AuditLog::orderBy('id')->pluck('event')->all());
        $this->assertEmpty(DB::selectOne("select current_setting('audit.paused', true) as v")->v);
    }

    public function test_upgrade_refreshes_old_function_and_constraint(): void
    {
        // simula instalação antiga: constraint sem P/R
        DB::statement('ALTER TABLE audit.logged_actions DROP CONSTRAINT logged_actions_event_check');
        DB::statement("ALTER TABLE audit.logged_actions ADD CONSTRAINT logged_actions_event_check CHECK (event IN ('I','U','D','T'))");

        $this->artisan('pg-audit:upgrade')->assertSuccessful();

        PgAudit::withoutAuditing(fn () => DB::table('posts')->insert(['title' => 'ok']));
        $this->assertSame(['P', 'R'], AuditLog::orderBy('id')->pluck('event')->all());
    }

    public function test_db_version_is_captured_and_upgrade_adds_column(): void
    {
        $expected = explode(' ', DB::selectOne("select current_setting('server_version') as v")->v)[0];

        DB::table('posts')->insert(['title' => 'v']);
        PgAudit::withoutAuditing(fn () => null);
        $this->assertSame([$expected, $expected, $expected], AuditLog::orderBy('id')->pluck('db_version')->all());

        // instalação antiga: sem a coluna
        DB::statement('ALTER TABLE audit.logged_actions DROP COLUMN db_version');
        $this->artisan('pg-audit:upgrade')->assertSuccessful();
        DB::table('posts')->insert(['title' => 'w']);
        $this->assertSame($expected, AuditLog::latest('id')->first()->db_version);
    }

    public function test_db_version_can_be_disabled(): void
    {
        AuditSchema::down();
        config(['pg-audit.capture_db_version' => false]);
        AuditSchema::up();
        Schema::dropIfExists('posts');
        Schema::create('posts', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->audit();
        });
        DB::table('posts')->insert(['title' => 'a']);

        $this->assertFalse(Schema::hasColumn('audit.logged_actions', 'db_version'));
        $this->assertSame(1, AuditLog::count());
    }

    public function test_capture_query_is_opt_in_per_table_and_truncated(): void
    {
        AuditSchema::down();
        config(['pg-audit.capture_query' => true, 'pg-audit.capture_query_tables' => ['posts'], 'pg-audit.query_max_length' => 40]);
        AuditSchema::up();
        Schema::dropIfExists('posts');
        Schema::create('posts', fn (Blueprint $t) => tap($t, function ($t) {
            $t->id();
            $t->string('title');
            $t->audit();
        }));
        Schema::dropIfExists('notes');
        Schema::create('notes', fn (Blueprint $t) => tap($t, function ($t) {
            $t->id();
            $t->string('body');
            $t->audit();
        }));

        DB::table('posts')->insert(['title' => 'a']);
        DB::table('notes')->insert(['body' => 'b']);

        $post = AuditLog::where('auditable_table', 'posts')->first();
        $note = AuditLog::where('auditable_table', 'notes')->first();
        $this->assertStringStartsWith('insert into "posts"', $post->query);
        $this->assertLessThanOrEqual(40, strlen($post->query));
        $this->assertNull($note->query);                                   // fora da lista
        $this->assertTrue(collect(PgAudit::audited())->firstWhere('table', 'public.posts')['captures_query']);
        $this->assertFalse(collect(PgAudit::audited())->firstWhere('table', 'public.notes')['captures_query']);

        Schema::dropIfExists('notes');
    }

    public function test_capture_query_requires_global_flag_and_upgrade_adds_column(): void
    {
        // padrão: desligado e sem coluna
        $this->assertFalse(Schema::hasColumn('audit.logged_actions', 'query'));
        try {
            PgAudit::enable('posts', captureQuery: true);
            $this->fail('deveria exigir capture_query');
        } catch (\InvalidArgumentException) {
        }

        // instalação antiga + ligou depois: upgrade cria a coluna, reabilitar liga a tabela
        config(['pg-audit.capture_query' => true]);
        $this->artisan('pg-audit:upgrade')->assertSuccessful();
        PgAudit::enable('posts');
        DB::table('posts')->insert(['title' => 'q']);

        $this->assertStringContainsString('insert into "posts"', AuditLog::first()->query);
    }

    public function test_model_declares_except_array_and_enable_accepts_class(): void
    {
        PgAudit::disable('posts');

        $this->artisan('pg-audit:enable', ['tables' => [AuditedPost::class], '--except' => ['extra_col']])->assertSuccessful();

        $row = PgAudit::audited()[0];
        $except = explode(',', $row['except']);
        foreach (['title_cache', 'views', 'extra_col', 'password'] as $col) {
            $this->assertContains($col, $except);
        }
        $this->assertSame('public.posts', $row['table']);

        config(['pg-audit.models' => [AuditedPost::class]]);
        $this->artisan('pg-audit:sync')->assertSuccessful();
        $this->assertCount(1, PgAudit::audited());
    }
}

class AuditedPost extends \Illuminate\Database\Eloquent\Model
{
    use \MendesAlexandre\PgAudit\Concerns\HasAuditLog;

    protected $table = 'posts';

    protected array $auditExcept = ['title_cache', 'views'];
}
