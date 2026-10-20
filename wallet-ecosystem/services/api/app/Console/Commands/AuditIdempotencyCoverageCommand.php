<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/**
 * E27 SC-001: Verifies all state-changing financial endpoints have idempotency middleware.
 * Exits non-zero when any POST/PATCH/PUT financial route is missing idempotency coverage.
 */
final class AuditIdempotencyCoverageCommand extends Command
{
    protected $signature   = 'reliability:audit-idempotency-coverage';
    protected $description = 'Verify all state-changing financial routes have idempotency middleware (SC-001)';

    private const FINANCIAL_PREFIXES = [
        'funding',
        'transfer',
        'withdrawal',
        'service',
        'qr',
        'agent-float',
        'commission',
    ];

    private const MUTATION_METHODS = ['POST', 'PUT', 'PATCH'];

    public function handle(): int
    {
        $routes = Route::getRoutes()->getRoutes();

        $uncovered = [];

        foreach ($routes as $route) {
            $methods = array_map('strtoupper', $route->methods());
            $uri     = ltrim($route->uri(), '/');

            // Only check mutation methods
            $hasMutation = array_intersect(self::MUTATION_METHODS, $methods);
            if (empty($hasMutation)) {
                continue;
            }

            // Only check financial module routes
            $isFinancial = false;
            foreach (self::FINANCIAL_PREFIXES as $prefix) {
                if (str_starts_with($uri, "v1/{$prefix}") || str_starts_with($uri, "v1/agent-float")) {
                    $isFinancial = true;
                    break;
                }
            }

            if (! $isFinancial) {
                continue;
            }

            // Check for idempotency middleware
            $middlewares = $route->gatherMiddleware();
            $hasIdempotency = false;
            foreach ($middlewares as $mw) {
                if (str_starts_with((string) $mw, 'idempotency')) {
                    $hasIdempotency = true;
                    break;
                }
            }

            if (! $hasIdempotency) {
                $uncovered[] = implode('|', $methods) . ' /' . $uri;
            }
        }

        if (count($uncovered) === 0) {
            $this->info('All financial mutation routes have idempotency middleware. SC-001 satisfied.');
            return self::SUCCESS;
        }

        $this->error('The following financial routes are missing idempotency middleware:');
        foreach ($uncovered as $route) {
            $this->line("  - {$route}");
        }

        return self::FAILURE;
    }
}
