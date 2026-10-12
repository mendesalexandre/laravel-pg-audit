<?php

namespace MendesAlexandre\PgAudit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Fluent;
use Illuminate\Support\ServiceProvider;
use MendesAlexandre\PgAudit\Console\DisableCommand;
use MendesAlexandre\PgAudit\Console\EnableCommand;
use MendesAlexandre\PgAudit\Console\PruneCommand;
use MendesAlexandre\PgAudit\Console\StatusCommand;
use MendesAlexandre\PgAudit\Console\SyncCommand;

class PgAuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/pg-audit.php', 'pg-audit');
        $this->app->singleton(AuditManager::class);
    }

    public function boot(): void
    {
        $this->registerBlueprintMacros();

        if ($this->app->runningInConsole()) {
            $this->commands([EnableCommand::class, DisableCommand::class, SyncCommand::class, StatusCommand::class, PruneCommand::class]);

            $this->publishes([__DIR__.'/../config/pg-audit.php' => config_path('pg-audit.php')], 'pg-audit-config');
            $this->publishes([
                __DIR__.'/../database/migrations/create_pg_audit_schema.php.stub' => database_path('migrations/'.date('Y_m_d_His').'_create_pg_audit_schema.php'),
            ], 'pg-audit-migrations');
        }
    }

    /**
     * $table->audit(except: ['updated_at']) / $table->dropAudit()
     * Viram comandos do Blueprint, executados após o CREATE/ALTER da tabela.
     */
    protected function registerBlueprintMacros(): void
    {
        Blueprint::macro('audit', function (array $except = [], ?bool $truncate = null, ?string $schema = null) {
            return $this->addCommand('audit', compact('except', 'truncate', 'schema'));
        });

        Blueprint::macro('dropAudit', function () {
            return $this->addCommand('dropAudit');
        });

        PostgresGrammar::macro('compileAudit', function (Blueprint $blueprint, Fluent $command) {
            return app(AuditManager::class)->enableStatements(
                $this->wrapTable($blueprint), $command->except ?? [], $command->truncate, $command->schema
            );
        });

        PostgresGrammar::macro('compileDropAudit', function (Blueprint $blueprint, Fluent $command) {
            return app(AuditManager::class)->disableStatements($this->wrapTable($blueprint));
        });
    }
}
