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
