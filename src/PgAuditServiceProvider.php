<?php

namespace MendesAlexandre\PgAudit;

use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Fluent;
use Illuminate\Support\ServiceProvider;
use MendesAlexandre\PgAudit\Console\DdlLogCommand;
use MendesAlexandre\PgAudit\Console\DeletedCommand;
use MendesAlexandre\PgAudit\Console\DisableCommand;
use MendesAlexandre\PgAudit\Console\EnableCommand;
use MendesAlexandre\PgAudit\Console\GrantCommand;
use MendesAlexandre\PgAudit\Console\InstallCommand;
use MendesAlexandre\PgAudit\Console\MakeMigrationCommand;
use MendesAlexandre\PgAudit\Console\PruneCommand;
use MendesAlexandre\PgAudit\Console\RenameColumnsCommand;
use MendesAlexandre\PgAudit\Console\RestoreCommand;
use MendesAlexandre\PgAudit\Console\StatusCommand;
use MendesAlexandre\PgAudit\Console\SyncCommand;
use MendesAlexandre\PgAudit\Console\UpgradeCommand;
use MendesAlexandre\PgAudit\Console\VersionCommand;

class PgAuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/pg-audit.php', 'pg-audit');
        // scoped: zerado a cada request (Octane) e a cada job, evitando vazar contexto entre eles.
        $this->app->scoped(AuditManager::class);
    }

    public function boot(): void
    {
        $this->registerBlueprintMacros();

        Event::listen(TransactionBeginning::class, fn (TransactionBeginning $e) => app(AuditManager::class)->applyStored($e->connection));

        $this->registerMigrationListeners();
        $this->app->booted(function () {
            if ($this->app->bound('db') && config('pg-audit.literal_query_tables')) {
                app(AuditManager::class)->hookLiteralQueries(DB::connection(config('pg-audit.connection')));
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([GrantCommand::class, RestoreCommand::class, DeletedCommand::class, VersionCommand::class, DdlLogCommand::class, UpgradeCommand::class, RenameColumnsCommand::class, InstallCommand::class, MakeMigrationCommand::class, EnableCommand::class, DisableCommand::class, SyncCommand::class, StatusCommand::class, PruneCommand::class]);

            $this->publishes([__DIR__.'/../config/pg-audit.php' => config_path('pg-audit.php')], 'pg-audit-config');
            $this->publishes([
                __DIR__.'/../database/migrations/create_pg_audit_schema.php.stub' => database_path('migrations/'.date('Y_m_d_His').'_create_pg_audit_schema.php'),
            ], 'pg-audit-migrations');
        }
    }

    /**
     * Durante o `migrate`: identifica a migration em andamento (para o histórico de DDL) e, ao fim, grava a versão do esquema.
     * Nunca quebra a migration: falhas aqui só são reportadas.
     */
    protected function registerMigrationListeners(): void
    {
        $safe = function (callable $fn) {
            try {
                if (DB::connection(config('pg-audit.connection'))->getDriverName() === 'pgsql') {
                    $fn(app(AuditManager::class));
                }
            } catch (\Throwable $e) {
                report($e);
            }
        };

        Event::listen(MigrationStarted::class, fn (MigrationStarted $e) => $safe(fn ($m) => $m->setMigration(AuditManager::migrationName($e->migration))));
        Event::listen(MigrationEnded::class, fn () => $safe(fn ($m) => $m->setMigration(null)));
        Event::listen(MigrationsEnded::class, function () use ($safe) {
            if (config('pg-audit.capture_schema_version', true)) {
                $safe(function ($m) {
                    if ($m->schemaState() !== null) {
                        $m->recordSchemaVersion();
                    }
                });
            }
        });
    }

    /**
     * $table->audit(except: ['updated_at']) / $table->dropAudit()
     * Viram comandos do Blueprint, executados após o CREATE/ALTER da tabela.
     */
    protected function registerBlueprintMacros(): void
    {
        Blueprint::macro('audit', function (array $except = [], ?bool $truncate = null, ?string $schema = null, ?string $prefix = null, ?bool $captureQuery = null) {
            return $this->addCommand('audit', compact('except', 'truncate', 'schema', 'prefix', 'captureQuery'));
        });

        Blueprint::macro('dropAudit', function () {
            return $this->addCommand('dropAudit');
        });

        PostgresGrammar::macro('compileAudit', function (Blueprint $blueprint, Fluent $command) {
            return app(AuditManager::class)->enableStatements(
                $this->wrapTable($blueprint), $command->except ?? [], $command->truncate, $command->schema, $command->prefix, $command->captureQuery
            );
        });

        PostgresGrammar::macro('compileDropAudit', function (Blueprint $blueprint, Fluent $command) {
            return app(AuditManager::class)->disableStatements($this->wrapTable($blueprint));
        });
    }
}
