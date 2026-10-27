<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Application\Jobs\PayoutQualificationJob;
use App\Modules\Payment\Domain\Contracts\ClearingRecordRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\ProviderStatementRepositoryInterface;
use App\Modules\Payment\Domain\Events\PayoutQualifiedEvent;
use App\Modules\Payment\Domain\ValueObjects\PayoutQualificationSummary;
use App\Modules\Payment\Infrastructure\Models\ClearingRecordModel;
use App\Modules\Payment\Infrastructure\Models\ProviderStatementModel;
use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class PayoutQualificationService
{
    public function __construct(
        private readonly ProviderStatementRepositoryInterface $statementRepository,
        private readonly ClearingRecordRepositoryInterface    $clearingRepository,
        private readonly OutboxEventPublisher                 $outbox,
        private readonly AuditServiceInterface                $audit,
    ) {}

    public function computeAndPublish(string $statementId): PayoutQualificationSummary
    {
        /** @var ProviderStatementModel|null $statement */
        $statement = $this->statementRepository->findById($statementId);

        if ($statement === null) {
            throw new \RuntimeException("ProviderStatement not found: {$statementId}");
        }

        if (!$statement->status->allowsQualification()) {
            throw new \InvalidArgumentException(
                "Statement {$statementId} in status {$statement->status->value} does not allow qualification."
            );
        }

        // Sum gross cleared amount from clearing records with accounting_status = 'qualified'
        $grossClearedMinor = (int) ClearingRecordModel::query()
            ->where('statement_id', $statementId)
            ->where('accounting_status', 'qualified')
            ->sum('cleared_amount_minor');

        // Sum excluded amount (pending_ledger_config records)
        $excludedAmountMinor = (int) ClearingRecordModel::query()
            ->where('statement_id', $statementId)
            ->where('accounting_status', 'pending_ledger_config')
            ->sum('cleared_amount_minor');

        // Sum fee deductions from matched statement lines of type 'fee'
        $feeDeductionsMinor = (int) DB::connection('payment')
            ->table('payment.provider_statement_lines')
            ->where('statement_id', $statementId)
            ->where('line_type', 'fee')
            ->where('match_status', 'matched')
            ->sum('amount_minor');

        // Sum dispute hold deductions from open dispute holds on matched attempts
        $disputeHoldDeductionsMinor = $this->computeDisputeHolds($statementId);

        // net = max(0, gross - fees - holds); excludes pending_ledger_config records
        $netQualifiedMinor = max(
            0,
            $grossClearedMinor - $feeDeductionsMinor - $disputeHoldDeductionsMinor
        );

        // Count open reconciliation exceptions linked to this statement's clearing records
        $openExceptionCount = (int) DB::connection('payment')
            ->table('payment.reconciliation_exceptions')
            ->join('payment.clearing_records', function ($join) {
                $join->on('reconciliation_exceptions.payment_attempt_id', '=', 'clearing_records.payment_attempt_id');
            })
            ->where('clearing_records.statement_id', $statementId)
            ->where('reconciliation_exceptions.status', 'open')
            ->count();

        $computedAt  = Carbon::now();
        $versionHash = hash('sha256',
            $grossClearedMinor
            . $feeDeductionsMinor
            . $disputeHoldDeductionsMinor
            . $openExceptionCount
        );

        $summary = new PayoutQualificationSummary(
            statementId:                $statementId,
            providerCode:               $statement->provider_code,
            currencyCode:               $statement->currency_code,
            periodStart:                Carbon::instance($statement->period_start),
            periodEnd:                  Carbon::instance($statement->period_end),
            grossClearedMinor:          $grossClearedMinor,
            feeDeductionsMinor:         $feeDeductionsMinor,
            disputeHoldDeductionsMinor: $disputeHoldDeductionsMinor,
            netQualifiedMinor:          $netQualifiedMinor,
            excludedAmountMinor:        $excludedAmountMinor,
            openExceptionCount:         $openExceptionCount,
            computedAt:                 $computedAt,
            versionHash:                $versionHash,
        );

        DB::connection('platform')->transaction(function () use ($summary): void {
            $this->outbox->publish(new PayoutQualifiedEvent($summary));
        });

        $this->audit->record(new AuditEventData(
            action:       'payment.reconciliation.payout_qualified',
            actorType:    'system',
            resourceType: 'provider_statement',
            correlationId: $statementId,
            outcome:      'success',
            resourceId:   $statementId,
            afterState:   $summary->toArray(),
        ));

        Log::info('PayoutQualificationService.computed', [
            'statement_id'        => $statementId,
            'net_qualified_minor' => $netQualifiedMinor,
            'open_exceptions'     => $openExceptionCount,
            'version_hash'        => $versionHash,
        ]);

        return $summary;
    }

    private function computeDisputeHolds(string $statementId): int
    {
        // Sum active dispute holds on attempts that were cleared in this statement
        $total = DB::connection('payment')
            ->table('payment.payment_dispute_cases')
            ->join('payment.clearing_records', function ($join) {
                $join->on('payment_dispute_cases.payment_attempt_id', '=', 'clearing_records.payment_attempt_id');
            })
            ->where('clearing_records.statement_id', $statementId)
            ->whereNotNull('payment_dispute_cases.held_amount_minor')
            ->whereIn('payment_dispute_cases.status', ['open', 'under_review', 'awaiting_evidence'])
            ->sum('payment_dispute_cases.held_amount_minor');

        return (int) $total;
    }
}
