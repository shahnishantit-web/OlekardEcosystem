<?php

declare(strict_types=1);

namespace App\Modules\Ops\Domain\Contracts;

/**
 * Optional interface for E25 provider adapters that support active health probing.
 * E25 ProviderAdapterInterface does not include health checks; adapters that can be
 * actively probed implement this interface in addition to ProviderAdapterInterface.
 */
interface HealthCheckableProviderInterface
{
    /**
     * Perform a lightweight health probe against the provider.
     *
     * @param  int  $timeoutSeconds  Max seconds to wait before treating as timeout
     * @return array{status: string, response_time_ms: ?int, error: ?string}
     *               status: 'success'|'timeout'|'error'
     */
    public function probe(int $timeoutSeconds): array;
}
