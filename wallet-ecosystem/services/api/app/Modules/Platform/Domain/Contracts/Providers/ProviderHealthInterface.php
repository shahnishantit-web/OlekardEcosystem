<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts\Providers;

use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderHealthStatus;

interface ProviderHealthInterface
{
    /**
     * Return the current health status of this provider adapter.
     *
     * MUST NOT make a live provider API call on each invocation.
     * Returns ProviderHealthStatus::unknown() as the safe initial state when no
     * health data has been received yet.
     */
    public function getHealthStatus(): ProviderHealthStatus;
}
