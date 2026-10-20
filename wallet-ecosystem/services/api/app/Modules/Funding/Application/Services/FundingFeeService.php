<?php

declare(strict_types=1);

namespace App\Modules\Funding\Application\Services;

use App\Modules\Funding\Domain\Enums\FundingMethod;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;

/**
 * Calculates the funding fee and net amount for a given funding request.
 *
 * Fee rules:
 * - Agent cash-in: always 0 (domain-enforced, per clarification A3)
 * - All other methods: flat percentage loaded from config key `funding.fee.{method}.rate`
 *   (e.g. `funding.fee.mobile_money.rate`); default 0 if unset
 *
 * All amounts are in minor currency units (integer).
 * Returns: ['fee_amount' => int, 'net_amount' => int]
 */
final class FundingFeeService
{
    public function __construct(
        private readonly ConfigurationServiceInterface $config,
    ) {}

    /**
     * @param int          $grossAmount  Amount before fee, minor units
     * @param FundingMethod $method
     * @return array{fee_amount: int, net_amount: int}
     */
    public function calculate(int $grossAmount, FundingMethod $method): array
    {
        if ($method === FundingMethod::Agent) {
            return ['fee_amount' => 0, 'net_amount' => $grossAmount];
        }

        $rateKey = 'funding.fee.' . $method->value . '.rate';
        $rate    = (float) ($this->config->get($rateKey) ?? 0.0);

        $feeAmount = (int) round($grossAmount * $rate);
        $netAmount = $grossAmount - $feeAmount;

        return ['fee_amount' => $feeAmount, 'net_amount' => $netAmount];
    }

    /**
     * Returns true if the recalculated fee differs from the originally-quoted fee.
     */
    public function hasFeeChanged(int $grossAmount, FundingMethod $method, int $quotedFeeAmount): bool
    {
        ['fee_amount' => $currentFee] = $this->calculate($grossAmount, $method);

        return $currentFee !== $quotedFeeAmount;
    }
}
