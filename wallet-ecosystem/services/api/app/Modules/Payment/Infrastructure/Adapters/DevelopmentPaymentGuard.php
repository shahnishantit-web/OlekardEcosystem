<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Adapters;

use App\Modules\Payment\Domain\Exceptions\ProductionBlockedException;

final class DevelopmentPaymentGuard
{
    public static function check(): void
    {
        if (! app()->environment('local', 'development', 'testing')) {
            throw new ProductionBlockedException('mock_bank');
        }
    }
}
