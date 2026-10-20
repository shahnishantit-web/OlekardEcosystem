<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Application\Services;

use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Withdrawal\Domain\Enums\WithdrawalChannel;
use App\Modules\Withdrawal\Domain\Enums\WithdrawalStatus;
use App\Modules\Withdrawal\Domain\Exceptions\WithdrawalLimitViolationException;
use App\Modules\Withdrawal\Infrastructure\Models\WithdrawalTransactionModel;
use Illuminate\Support\Carbon;

final class WithdrawalLimitService
{
    private const DEFAULT_MIN       = 100;
    private const DEFAULT_MAX       = 1_000_000;
    private const DEFAULT_DAILY_MAX = 5_000_000;
    private const DEFAULT_TIER      = 'tier1';

    public function __construct(private readonly ConfigurationServiceInterface $config) {}

    /**
     * Validate a withdrawal amount against per-transaction and daily limits.
     *
     * @throws WithdrawalLimitViolationException
     */
    public function validate(
        int $grossAmount,
        WithdrawalChannel $channel,
        string $currencyCode,
        string $customerId,
        ?string $excludeTransactionId = null,
    ): void {
        $tier = self::DEFAULT_TIER;
        $base = "withdrawal.limit.{$channel->value}.{$tier}";

        $min      = (int) ($this->config->get("{$base}.min_amount") ?? self::DEFAULT_MIN);
        $max      = (int) ($this->config->get("{$base}.max_amount") ?? self::DEFAULT_MAX);
        $dailyMax = (int) ($this->config->get("{$base}.daily_max")  ?? self::DEFAULT_DAILY_MAX);

        if ($grossAmount < $min) {
            throw WithdrawalLimitViolationException::belowMinimum($grossAmount, $min);
        }

        if ($grossAmount > $max) {
            throw WithdrawalLimitViolationException::aboveMaximum($grossAmount, $max);
        }

        $dailyUsed = $this->dailyUsed($customerId, $currencyCode, $excludeTransactionId);

        if (($dailyUsed + $grossAmount) > $dailyMax) {
            throw WithdrawalLimitViolationException::dailyLimitExceeded($grossAmount, $dailyMax, $dailyUsed);
        }
    }

    public function getLimits(WithdrawalChannel $channel, string $currencyCode, string $customerId): array
    {
        $tier = self::DEFAULT_TIER;
        $base = "withdrawal.limit.{$channel->value}.{$tier}";

        $min      = (int) ($this->config->get("{$base}.min_amount") ?? self::DEFAULT_MIN);
        $max      = (int) ($this->config->get("{$base}.max_amount") ?? self::DEFAULT_MAX);
        $dailyMax = (int) ($this->config->get("{$base}.daily_max")  ?? self::DEFAULT_DAILY_MAX);
        $dailyUsed = $this->dailyUsed($customerId, $currencyCode, null);

        return [
            'min_amount'       => $min,
            'max_amount'       => $max,
            'daily_remaining'  => max(0, $dailyMax - $dailyUsed),
        ];
    }

    private function dailyUsed(string $customerId, string $currencyCode, ?string $excludeId): int
    {
        $query = WithdrawalTransactionModel::query()
            ->where('customer_id', $customerId)
            ->where('pocket_currency_code', $currencyCode)
            ->whereIn('status', [
                WithdrawalStatus::Pending->value,
                WithdrawalStatus::Processing->value,
                WithdrawalStatus::Completed->value,
            ])
            ->where('created_at', '>=', Carbon::today('UTC'));

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return (int) $query->sum('gross_amount');
    }
}
