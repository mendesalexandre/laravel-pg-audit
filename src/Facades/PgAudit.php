<?php

namespace MendesAlexandre\PgAudit\Facades;

use Illuminate\Support\Facades\Facade;
use MendesAlexandre\PgAudit\AuditManager;

/**
 * @method static void enable(string $table, array $except = [], ?bool $truncate = null)
 * @method static void disable(string $table)
 * @method static array audited()
 * @method static void setContext(array $context)
 * @method static void clearContext()
 * @method static mixed actingAs(string|int|null $userId, \Closure $callback)
 *
 * @see AuditManager
 */
class PgAudit extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AuditManager::class;
    }
}
