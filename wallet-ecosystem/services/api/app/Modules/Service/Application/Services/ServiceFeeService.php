<?php

declare(strict_types=1);

namespace App\Modules\Service\Application\Services;

use App\Modules\Service\Domain\Enums\ServiceFeeType;
use App\Modules\Service\Domain\Exceptions\AmountOutOfRangeException;
use App\Modules\Service\Domain\Exceptions\FeeChangedException;
use App\Modules\Service\Infrastructure\Models\ServiceModel;

final class ServiceFeeService
{
    public function calculate(ServiceModel $service, int $amount): array
    {
        if ($service->min_amount !== null && $amount < $service->min_amount) {
            throw new AmountOutOfRangeException($amount, $service->min_amount, $service->max_amount ?? PHP_INT_MAX);
        }

        if ($service->max_amount !== null && $amount > $service->max_amount) {
            throw new AmountOutOfRangeException($amount, $service->min_amount ?? 0, $service->max_amount);
        }

        $feeAmount = match ($service->fee_type) {
            ServiceFeeType::Flat       => $service->fee_value,
            ServiceFeeType::Percentage => (int) round($amount * $service->fee_value / 10000),
        };

        return [
            'amount'        => $amount,
            'fee_amount'    => $feeAmount,
            'total_amount'  => $amount + $feeAmount,
            'currency_code' => $service->currency_code,
        ];
    }

    public function assertFeeUnchanged(ServiceModel $service, int $quotedFeeAmount, int $amount): void
    {
        $current = $this->calculate($service, $amount);

        if ($current['fee_amount'] !== $quotedFeeAmount) {
            throw new FeeChangedException(
                newFeeAmount:   $current['fee_amount'],
                newTotalAmount: $current['total_amount'],
            );
        }
    }
}
