<?php

namespace MendesAlexandre\PgAudit\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
}
