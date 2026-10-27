<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

use App\Modules\Payment\Domain\Enums\DisputeEntitlementAction;

final readonly class DisputeEntitlementResult
{
    public function __construct(
        public readonly DisputeEntitlementAction $action,
        public readonly ?string                  $evidenceReference = null,
        public readonly ?string                  $notes             = null,
    ) {
    }
}
