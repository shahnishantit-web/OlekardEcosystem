<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Application\Services;

use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;

/**
 * Calculates transfer fee and net amount from gross amount and rate (basis points).
 *
 * Formula: fee = intval(round(gross * rate_bps / 10000))
 * Default fee rate is 0 bps (zero fee) when unset in configuration.
 */
final class TransferFeeService
{
    public function __construct(
        private readonly ConfigurationServiceInterface $config,
    ) {}

    /**
     * @return array{fee_amount: int, net_amount: int, fee_rate_bps: int}
     */
    public function calculate(int $grossAmount, int $rateBps): array
    {
        $feeAmount = intval(round($grossAmount * $rateBps / 10000));
        $netAmount = $grossAmount - $feeAmount;

        return [
            'fee_amount'    => $feeAmount,
            'net_amount'    => $netAmount,
            'fee_rate_bps'  => $rateBps,
        ];
    }

    public function getCurrentRateBps(string $currencyCode = 'BWP'): int
    {
        return (int) ($this->config->getOrDefault('transfer.fee.rate_bps', 0));
    }

    public function hasFeeRateChanged(int $quotedRateBps, string $currencyCode = 'BWP'): bool
    {
        return $this->getCurrentRateBps($currencyCode) !== $quotedRateBps;
    }
}
