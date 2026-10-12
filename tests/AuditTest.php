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
        $this->assertSame(['I', 'U', 'D'], $logs->pluck('action')->all());
        $this->assertSame(['title' => 'a'], $logs[1]->old_values);
        $this->assertSame(['title' => 'b'], $logs[1]->new_values);
        $this->assertSame('42', $logs[0]->user_id);
        $this->assertSame('1', $logs[0]->row_pk);
        $this->assertArrayNotHasKey('updated_at', $logs[2]->old_values);
    }

    public function test_context_restored_and_truncate_logged(): void
    {
        PgAudit::actingAs(1, fn () => null);
        DB::table('posts')->insert(['title' => 'x']);
        DB::table('posts')->truncate();

        $this->assertNull(AuditLog::where('action', 'I')->first()->user_id);
        $this->assertSame(1, AuditLog::where('action', 'T')->count());
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
        $this->assertSame('invoices', AuditLog::inSchema('audit_fin', null, 'movimentos')->first()->table_name);
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
}
