<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Traits;

use App\Modules\Payment\Domain\Exceptions\ConcurrentModificationException;
use Illuminate\Support\Facades\Log;

trait DeadlockRetryTrait
{
    /**
     * Execute $callback inside a retry loop for PostgreSQL deadlock errors (SQLSTATE 40P01).
     *
     * @throws ConcurrentModificationException when retries are exhausted
     */
    private function withDeadlockRetry(callable $callback, string $resourceLabel): mixed
    {
        $maxRetries = (int) ($this->config->getOrDefault('payment.deadlock_max_retries', 3) ?? 3);
        $baseMs     = (int) ($this->config->getOrDefault('payment.deadlock_retry_base_ms', 10) ?? 10);

        $attempt = 0;

        while (true) {
            try {
                return $callback();
            } catch (\Illuminate\Database\QueryException $e) {
                if ($e->getCode() !== '40P01') {
                    throw $e;
                }

                $attempt++;
                Log::warning('DeadlockRetry: deadlock detected', [
                    'resource' => $resourceLabel,
                    'attempt'  => $attempt,
                    'max'      => $maxRetries,
                ]);

                if ($attempt >= $maxRetries) {
                    throw new ConcurrentModificationException($resourceLabel, $attempt);
                }

                // Exponential backoff: base × 2^(attempt-1) ms
                $sleepUs = $baseMs * (2 ** ($attempt - 1)) * 1000;
                usleep($sleepUs);
            }
        }
    }
}
