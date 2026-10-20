<?php

declare(strict_types=1);

namespace App\Modules\Fees\Application\Services;

use App\Modules\Fees\Domain\Contracts\FeesModuleContract;
use App\Modules\Fees\Domain\ValueObjects\FeeCalculationResult;

final class FeesServiceFacade implements FeesModuleContract
{
    public function __construct(
        private readonly FeeCalculationService $calculator,
        private readonly FeeLedgerService      $ledger,
    ) {}

    public function calculateFee(
        string  $transactionType,
        int     $amountMinor,
        string  $currency,
        string  $subjectType,
        ?string $subjectId,
    ): FeeCalculationResult {
        return $this->calculator->calculate($transactionType, $amountMinor, $currency, $subjectType, $subjectId);
    }

    public function recordFee(
        string  $transactionId,
        int     $amountMinor,
        ?string $feeRuleId,
        string  $currency,
        string $debitAccountRef,
        string $creditAccountRef,
        string $idempotencyKey,
        string $correlationId,
    ): string {
        return $this->ledger->recordFee(
            $transactionId, $amountMinor, $feeRuleId, $currency,
            $debitAccountRef, $creditAccountRef, $idempotencyKey, $correlationId,
        );
    }

    public function reverseFee(
        string $transactionId,
        string $debitAccountRef,
        string $creditAccountRef,
        string $idempotencyKey,
        string $correlationId,
    ): void {
        $this->ledger->reverseFee($transactionId, $debitAccountRef, $creditAccountRef, $idempotencyKey, $correlationId);
    }
}
