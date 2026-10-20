<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Application\Services;

use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Transfer\Domain\Enums\TransferStatus;
use App\Modules\Transfer\Domain\Exceptions\DailyLimitExceededException;
use App\Modules\Transfer\Domain\Exceptions\InsufficientBalanceException;
use App\Modules\Transfer\Infrastructure\Models\TransferTransactionModel;
use Illuminate\Support\Carbon;

final class TransferLimitService
{
    private const DEFAULT_MIN        = 100;
    private const DEFAULT_MAX        = 500_000;
    private const DEFAULT_DAILY_MAX  = 2_000_000;

    public function __construct(
        private readonly ConfigurationServiceInterface $config,
    ) {}

    /**
     * @throws \InvalidArgumentException
     */
    public function validateMin(int $amount, string $currency = 'BWP'): void
    {
        $min = (int) $this->config->getOrDefault('transfer.min_amount', self::DEFAULT_MIN);

        if ($amount < $min) {
            throw new \InvalidArgumentException(
                "Amount {$amount} is below minimum {$min} for {$currency}."
            );
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function validateMax(int $amount, string $currency = 'BWP'): void
    {
        $max = (int) $this->config->getOrDefault('transfer.max_amount', self::DEFAULT_MAX);

        if ($amount > $max) {
            throw new \InvalidArgumentException(
                "Amount {$amount} exceeds maximum {$max} for {$currency}."
            );
        }
    }

    /**
     * @throws DailyLimitExceededException
     * @param  string|null $excludeTransferId Exclude this transfer ID from the sum (used at confirmation).
     */
    public function validateDailyLimit(
        string $senderCustomerId,
        int    $newGrossAmount,
        string $currency = 'BWP',
        ?string $excludeTransferId = null,
    ): void {
        $dailyMax  = (int) $this->config->getOrDefault('transfer.daily_limit', self::DEFAULT_DAILY_MAX);
        $todaySpent = $this->dailyTotalForSender($senderCustomerId, $currency, $excludeTransferId);
        $projected  = $todaySpent + $newGrossAmount;

        if ($projected > $dailyMax) {
            $remaining = max(0, $dailyMax - $todaySpent);
            throw new DailyLimitExceededException(remaining: $remaining);
        }
    }

    /**
     * @throws InsufficientBalanceException
     */
    public function validateBalance(int $grossAmount, int $availableBalance): void
    {
        if ($availableBalance < $grossAmount) {
            throw InsufficientBalanceException::forAmount($grossAmount, $availableBalance);
        }
    }

    private function dailyTotalForSender(
        string  $senderCustomerId,
        string  $currency,
        ?string $excludeTransferId = null,
    ): int {
        $query = TransferTransactionModel::query()
            ->where('sender_customer_id', $senderCustomerId)
            ->where('currency_code', $currency)
            ->whereIn('status', [TransferStatus::Pending->value, TransferStatus::Completed->value])
            ->where('created_at', '>=', Carbon::today());

        if ($excludeTransferId !== null) {
            $query->where('id', '!=', $excludeTransferId);
        }

        return (int) $query->sum('gross_amount');
    }
}
