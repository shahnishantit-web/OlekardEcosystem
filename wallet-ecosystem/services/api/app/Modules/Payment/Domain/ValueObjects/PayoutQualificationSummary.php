<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

use Illuminate\Support\Carbon;

final readonly class PayoutQualificationSummary
{
    public function __construct(
        public string $statementId,
        public string $providerCode,
        public string $currencyCode,
        public Carbon $periodStart,
        public Carbon $periodEnd,
        public int    $grossClearedMinor,
        public int    $feeDeductionsMinor,
        public int    $disputeHoldDeductionsMinor,
        public int    $netQualifiedMinor,
        public int    $excludedAmountMinor,
        public int    $openExceptionCount,
        public Carbon $computedAt,
        public string $versionHash,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'statement_id'                => $this->statementId,
            'provider_code'               => $this->providerCode,
            'currency_code'               => $this->currencyCode,
            'period_start'                => $this->periodStart->toIso8601String(),
            'period_end'                  => $this->periodEnd->toIso8601String(),
            'gross_cleared_minor'         => $this->grossClearedMinor,
            'fee_deductions_minor'        => $this->feeDeductionsMinor,
            'dispute_hold_deductions_minor' => $this->disputeHoldDeductionsMinor,
            'net_qualified_minor'         => $this->netQualifiedMinor,
            'excluded_amount_minor'       => $this->excludedAmountMinor,
            'open_exception_count'        => $this->openExceptionCount,
            'computed_at'                 => $this->computedAt->toIso8601String(),
            'version_hash'                => $this->versionHash,
        ];
    }
}
