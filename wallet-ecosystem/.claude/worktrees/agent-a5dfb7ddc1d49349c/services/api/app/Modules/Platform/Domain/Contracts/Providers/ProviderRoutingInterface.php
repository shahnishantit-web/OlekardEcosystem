<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts\Providers;

use App\Modules\Platform\Domain\Exceptions\ProviderException;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderRequest;

/**
 * Port for selecting a provider adapter for a given request.
 *
 * This is a routing seam only — it returns the selected ProviderAdapterInterface
 * from the supplied candidates array. Concrete routing strategies (ordered list,
 * round-robin, weighted, geo-affined, etc.) are implemented in downstream epic
 * Infrastructure layers and must be registered via the container without
 * modifying this interface.
 *
 * Implementor obligations:
 * 1. MUST NOT select a provider whose ProviderHealthStatus is DOWN.
 * 2. MUST NOT select a provider whose ProviderCapabilitySet does not include
 *    the operation type from $context.
 * 3. MUST throw ProviderException if no eligible candidate exists (fail-fast;
 *    never silently use an ineligible provider).
 * 4. MUST NOT make live provider API calls to determine availability.
 * 5. Routing policy parameters (priority order, weights, thresholds) remain
 *    TBD/configurable per TBD-E1000-007.
 */
interface ProviderRoutingInterface
{
    /**
     * Select an eligible provider adapter for the given request context.
     *
     * @param  ProviderRequest              $context     Normalized outbound request carrying operationType.
     * @param  ProviderAdapterInterface[]   $candidates  All bound providers for this category.
     *
     * @return ProviderAdapterInterface  The selected adapter.
     *
     * @throws ProviderException  If no eligible provider is found in $candidates.
     */
    public function selectProvider(ProviderRequest $context, array $candidates): ProviderAdapterInterface;
}
