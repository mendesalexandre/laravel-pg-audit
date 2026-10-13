<?php

namespace MendesAlexandre\PgAudit\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mpyw\LaravelPdoEmulationControl\ConnectionServiceProvider;
use MendesAlexandre\PgAudit\AuditManager;
use MendesAlexandre\PgAudit\Database\AuditSchema;
use MendesAlexandre\PgAudit\Models\AuditLog;
use MendesAlexandre\PgAudit\PgAuditServiceProvider;

/**
 * Roda toda a suíte com o pacote opcional mpyw/laravel-pdo-emulation-control registrado:
 * PgAudit::withLiteralQueries() passa a delegar para DB::emulated().
 */
class AuditWithEmulationControlTest extends AuditTest
{
    protected function getPackageProviders($app): array
    {
        return [PgAuditServiceProvider::class, ConnectionServiceProvider::class];
    }

    public function test_with_literal_queries_delegates_to_emulation_control_and_restores_nested(): void
    {
        $this->assertTrue(method_exists(DB::connection(), 'emulated'), 'ConnectionServiceProvider do pacote opcional não está ativo');

        AuditSchema::down();
        config(['pg-audit.capture_query' => true, 'pg-audit.query_max_length' => 300]);
        AuditSchema::up();
        Schema::dropIfExists('posts');
        Schema::create('posts', fn (Blueprint $t) => tap($t, function ($t) {
            $t->id();
            $t->string('title');
            $t->audit(captureQuery: true);
        }));
        $manager = app(AuditManager::class);

        $manager->withLiteralQueries(function () use ($manager) {
            DB::table('posts')->insert(['title' => 'externo']);
            $manager->withLiteralQueries(fn () => DB::table('posts')->insert(['title' => 'interno']));   // aninhado
            DB::table('posts')->insert(['title' => 'externo 2']);                                         // ainda emulado
            $this->assertTrue($manager->emulatesPrepares());
        });
        DB::table('posts')->insert(['title' => 'fora']);
        $this->assertFalse($manager->emulatesPrepares());

        $this->assertSame(
            ['insert into "posts" ("title") values (\'externo\')', 'insert into "posts" ("title") values (\'interno\')',
             'insert into "posts" ("title") values (\'externo 2\')', 'insert into "posts" ("title") values ($1)'],
            AuditLog::orderBy('id')->pluck('query')->all()
        );
    }
}
