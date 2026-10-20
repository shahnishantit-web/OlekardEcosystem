<?php

declare(strict_types=1);

namespace App\Modules\Funding\Application\Services;

use App\Modules\Funding\Domain\Enums\FundingMethod;
use App\Modules\Funding\Domain\Enums\FundingStatus;
use App\Modules\Funding\Domain\Exceptions\FundingLimitExceededException;
use App\Modules\Funding\Infrastructure\Models\FundingTransactionModel;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use Illuminate\Support\Carbon;

/**
 * Validates per-transaction and daily funding limits.
 *
 * Config keys (all in minor currency units):
 *   funding.limits.min_amount            (default: 100 = 1.00 BWP)
 *   funding.limits.max_per_transaction   (default: 500000 = 5000.00 BWP)
 *   funding.limits.daily_max             (default: 2000000 = 20000.00 BWP)
 *
 * FR-E08-011: Agents funding on behalf of customers are exempt from daily limits
 * because the debit is from the agent's float, not the customer's daily allowance.
 */
final class FundingLimitService
{
    private const DEFAULT_MIN         = 100;
    private const DEFAULT_MAX_TX      = 500_000;
    private const DEFAULT_DAILY_MAX   = 2_000_000;

    public function __construct(
        private readonly ConfigurationServiceInterface $config,
    ) {}

    /**
     * @throws FundingLimitExceededException
     */
    public function assertWithinLimits(
        string $customerId,
        int $amount,
        FundingMethod $method,
        string $currencyCode,
    ): void {
        $this->assertMinimum($amount);
        $this->assertTransactionMaximum($amount);

        // Agent cash-in debits the agent float; skip the customer daily limit check
        if ($method !== FundingMethod::Agent) {
            $this->assertDailyLimit($customerId, $amount, $currencyCode);
        }
    }

    /**
     * Returns the customer's total funded amount today (excluding agent cash-ins).
     */
    public function dailyTotalForCustomer(string $customerId, string $currencyCode): int
    {
        return (int) FundingTransactionModel::query()
            ->where('customer_id', $customerId)
            ->where('pocket_currency_code', $currencyCode)
            ->where('funding_method', '!=', FundingMethod::Agent->value)
            ->whereIn('status', [
                FundingStatus::Pending->value,
                FundingStatus::Processing->value,
                FundingStatus::AwaitingMatch->value,
                FundingStatus::Completed->value,
            ])
            ->where('created_at', '>=', Carbon::today())
            ->sum('amount');
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function assertMinimum(int $amount): void
    {
        $min = (int) ($this->config->get('funding.limits.min_amount') ?? self::DEFAULT_MIN);

        if ($amount < $min) {
            throw FundingLimitExceededException::minimumNotMet($amount, $min);
        }
    }

    private function assertTransactionMaximum(int $amount): void
    {
        $max = (int) ($this->config->get('funding.limits.max_per_transaction') ?? self::DEFAULT_MAX_TX);

        if ($amount > $max) {
            throw FundingLimitExceededException::transactionMaxExceeded($amount, $max);
        }
    }

    private function assertDailyLimit(string $customerId, int $amount, string $currencyCode): void
    {
        $dailyMax    = (int) ($this->config->get('funding.limits.daily_max') ?? self::DEFAULT_DAILY_MAX);
        $dailySpent  = $this->dailyTotalForCustomer($customerId, $currencyCode);
        $projectedTotal = $dailySpent + $amount;

        if ($projectedTotal > $dailyMax) {
            throw FundingLimitExceededException::dailyLimitExceeded($amount, $dailyMax, $dailySpent);
        }
    }
}
