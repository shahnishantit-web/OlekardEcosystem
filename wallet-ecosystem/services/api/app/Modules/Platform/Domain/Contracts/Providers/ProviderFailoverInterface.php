<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts\Providers;

use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderError;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderRequest;

/**
 * Port for deciding whether and how to fail over to an alternate provider.
 *
 * This is a failover seam only. Concrete strategies (ordered list, circuit-breaker
 * state, configurable chain length) are implemented in downstream Infrastructure
 * layers and must be registered via the container without modifying this interface.
 *
 * Implementor obligations:
 * 1. shouldFailover() MUST return true only when $error->isTransient === true.
 *    Permanent errors (INSUFFICIENT_FUNDS, INVALID_REFERENCE, CREDENTIAL_REJECTED,
 *    etc.) MUST return false — no failover for permanent failures.
 * 2. selectFallback() MUST NOT return $current (prevents infinite failover loops).
 * 3. selectFallback() MUST NOT return a provider with health DOWN or a capability
 *    set that does not include the required operation.
 * 4. selectFallback() returns null when the fallback chain is exhausted — the
 *    caller is responsible for surfacing a terminal failure to the domain.
 * 5. Failover threshold, chain length, cooldown, and circuit-breaker parameters
 *    remain TBD/configurable per TBD-E1000-006, TBD-E1000-007, TBD-E1000-008.
 * 6. MUST NOT make live provider API calls.
 */
interface ProviderFailoverInterface
{
    /**
     * Determine whether the given error warrants a failover attempt.
     *
     * @param  ProviderError $error  The normalized error from the failed provider call.
     *
     * @return bool  True if failover should be attempted (transient error); false if terminal.
     */
    public function shouldFailover(ProviderError $error): bool;

    /**
     * Select the next provider in the failover chain.
     *
     * @param  ProviderAdapterInterface   $current    The provider that just failed.
     * @param  ProviderAdapterInterface[] $candidates All bound providers for this category.
     * @param  ProviderRequest            $context    The original request (for capability/health checks).
     *
     * @return ProviderAdapterInterface|null  Next eligible provider; null when chain is exhausted.
     */
    public function selectFallback(
        ProviderAdapterInterface $current,
        array $candidates,
        ProviderRequest $context,
    ): ?ProviderAdapterInterface;
}
