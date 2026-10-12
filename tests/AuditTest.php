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
        $this->assertSame('updated_at,remember_token', implode(',', array_reverse(explode(',', PgAudit::audited()[0]['except']))));

        PgAudit::disable('posts');
        $this->assertSame([], PgAudit::audited());
    }
}
