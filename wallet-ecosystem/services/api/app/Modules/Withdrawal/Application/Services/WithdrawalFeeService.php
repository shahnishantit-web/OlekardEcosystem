<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Application\Services;

use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Withdrawal\Domain\Enums\WithdrawalChannel;

final class WithdrawalFeeService
{
    public function __construct(private readonly ConfigurationServiceInterface $config) {}

    /**
     * @return array{fee_amount: int, net_payout_amount: int, fee_rate_bps: int}
     */
    public function calculate(int $grossAmount, WithdrawalChannel $channel, string $currencyCode): array
    {
        $key     = "withdrawal.fee.{$channel->value}.{$currencyCode}";
        $rateBps = (int) ($this->config->get($key) ?? 0);

        $feeAmount        = (int) round($grossAmount * $rateBps / 10000);
        $netPayoutAmount  = $grossAmount - $feeAmount;

        return [
            'fee_amount'       => $feeAmount,
            'net_payout_amount' => $netPayoutAmount,
            'fee_rate_bps'     => $rateBps,
        ];
    }
}
