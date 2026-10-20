<?php

declare(strict_types=1);

namespace App\Modules\Qa\Application\Executors\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Wraps a callable in a DB transaction that is always rolled back.
 * This allows financial executors to perform assertions against real DB state
 * without committing any mutations.
 */
trait FinancialIsolation
{
    /**
     * Run $callback inside a transaction that is always rolled back.
     * Returns whatever the callback returns.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    protected function inRolledBackTransaction(callable $callback): mixed
    {
        DB::connection('platform')->beginTransaction();

        try {
            return $callback();
        } finally {
            DB::connection('platform')->rollBack();
        }
    }
}
