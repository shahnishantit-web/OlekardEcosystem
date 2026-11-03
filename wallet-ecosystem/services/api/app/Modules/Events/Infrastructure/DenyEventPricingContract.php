<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure;

use App\Modules\Events\Domain\Contracts\EventPricingContract;
use App\Modules\Events\Domain\Exceptions\EventPricingUnavailable;
use App\Modules\Events\Domain\ValueObjects\EventPricingBreakdown;
use App\Modules\Events\Domain\ValueObjects\EventPricingRequest;

/** Production-safe default until Finance pricing and seller contracts are approved and bound. */
final class DenyEventPricingContract implements EventPricingContract
{
    public function quote(EventPricingRequest $request): EventPricingBreakdown
    {
        throw new EventPricingUnavailable('Approved Events fee, tax and seller pricing evidence is unavailable.');
    }
}
