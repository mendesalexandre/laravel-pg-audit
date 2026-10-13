<?php

namespace MendesAlexandre\PgAudit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use MendesAlexandre\PgAudit\AuditManager;
use Symfony\Component\HttpFoundation\Response;

class SetAuditContext
{
    public function __construct(protected AuditManager $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), config('pg-audit.skip_methods', []), true)) {
            return $next($request);
        }

        $context = [
            'user_id' => (string) ($request->user()?->getAuthIdentifier() ?? ''),
            'ip_address' => (string) $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'url' => mb_substr($request->fullUrl(), 0, 1000),
        ];

        foreach ($this->connections() as $connection) {
            $this->audit->setContext($context, $connection);
        }

        // Modo transaction (PgBouncer): o contexto só vale dentro de transação, então envolve o request em uma.
        if ($this->audit->mode() === 'transaction' && config('pg-audit.wrap_request_in_transaction', true)) {
            $run = array_reduce(
                array_reverse($this->connections()),
                fn (Closure $inner, $connection) => fn () => $this->audit->connection($connection)->transaction($inner),
                fn () => $next($request)
            );

            return $run();
        }

        return $next($request);
    }

    /** Evita vazar contexto entre requests (Octane / conexões persistentes). */
    public function terminate(Request $request, Response $response): void
    {
        foreach ($this->connections() as $connection) {
            $this->audit->clearContext($connection);
        }
    }

    /** Conexão principal + conexões extras (config "context_connections"). */
    protected function connections(): array
    {
        return array_values(array_unique(
            array_merge([config('pg-audit.connection')], config('pg-audit.context_connections', [])),
            SORT_REGULAR
        ));
    }
}
