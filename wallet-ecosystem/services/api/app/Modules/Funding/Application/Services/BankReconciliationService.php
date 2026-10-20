<?php

declare(strict_types=1);

namespace App\Modules\Funding\Application\Services;

use App\Modules\Funding\Infrastructure\Models\UnmatchedDepositModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;

/**
 * Handles manual admin resolution of unmatched deposits.
 *
 * Resolution options:
 *   matched   — link to an existing FundingTransaction and post credit
 *   rejected  — mark as rejected (e.g., returned to sender)
 */
final class BankReconciliationService
{
    public function __construct(
        private readonly FundingCreditService  $creditService,
        private readonly AuditServiceInterface $audit,
    ) {}

    /**
     * @param array{
     *   unmatched_deposit_id:   string,
     *   resolution:             string,
     *   funding_transaction_id: string|null,
     *   resolution_notes:       string|null,
     *   resolved_by:            string,
     *   correlation_id:         string,
     * } $request
     */
    public function resolveUnmatched(array $request): UnmatchedDepositModel
    {
        $depositId          = $request['unmatched_deposit_id'];
        $resolution         = $request['resolution'];
        $fundingTxId        = $request['funding_transaction_id'] ?? null;
        $resolutionNotes    = $request['resolution_notes'] ?? null;
        $resolvedBy         = $request['resolved_by'];
        $correlationId      = $request['correlation_id'];

        $deposit = UnmatchedDepositModel::where('id', $depositId)
            ->where('resolution_status', 'unresolved')
            ->firstOrFail();

        DB::transaction(function () use ($deposit, $resolution, $fundingTxId, $resolutionNotes, $resolvedBy, $correlationId): void {
            if ($resolution === 'matched' && $fundingTxId !== null) {
                $this->creditService->credit([
                    'transaction_id'        => $fundingTxId,
                    'provider_reference_id' => 'ADMIN-MATCH-' . $deposit->id,
                    'correlation_id'        => $correlationId,
                ]);
            }

            $deposit->resolution_status     = $resolution;
            $deposit->funding_transaction_id = $fundingTxId;
            $deposit->resolved_by           = $resolvedBy;
            $deposit->resolution_notes      = $resolutionNotes;
            $deposit->resolved_at           = now();
            $deposit->save();
        });

        $this->audit->record(new AuditEventData(
            action:       'funding.unmatched-deposit.resolved',
            actorType:    'ADMIN',
            resourceType: 'unmatched_deposit',
            correlationId: $correlationId,
            outcome:      'success',
            actorId:      $resolvedBy,
            resourceId:   $depositId,
            afterState:   ['resolution' => $resolution, 'funding_transaction_id' => $fundingTxId],
        ));

        return $deposit;
    }
}
