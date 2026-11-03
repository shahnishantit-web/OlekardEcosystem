<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\Contracts;

use App\Modules\Events\Domain\ValueObjects\EventPricingBreakdown;
use App\Modules\Events\Domain\ValueObjects\EventPricingRequest;

interface EventPricingContract
{
    public function quote(EventPricingRequest $request): EventPricingBreakdown;
}
