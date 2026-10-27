<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Payment\Domain\Contracts\ReconciliationCorrectionRepositoryInterface;
use App\Modules\Payment\Domain\Enums\CorrectionRequestStatus;
use App\Modules\Payment\Domain\Events\CorrectionPostedEvent;
use App\Modules\Payment\Domain\Exceptions\CorrectionApprovalException;
use App\Modules\Payment\Infrastructure\Models\ReconciliationCorrectionRequestModel;
use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

final class ReconciliationCorrectionService
{
    public function __construct(
        private readonly ReconciliationCorrectionRepositoryInterface $repository,
        private readonly LedgerModuleContract                        $ledger,
        private readonly ReconciliationExceptionService              $exceptionService,
        private readonly OutboxEventPublisher                        $outbox,
        private readonly AuditServiceInterface                       $audit,
        private readonly MetricsCollectorInterface                   $metrics,
    ) {}

    public function submit(
        string  $exceptionId,
        string  $correctionType,
        string  $description,
        string  $evidenceNote,
        int     $amountMinor,
        string  $currencyCode,
        string  $requestedBy,
        ?string $evidenceReference = null,
        ?string $debitAccount = null,
        ?string $creditAccount = null,
    ): ReconciliationCorrectionRequestModel {
        if (strlen($evidenceNote) < 10) {
            throw ValidationException::withMessages([
                'evidence_note' => ['The evidence_note must be at least 10 characters.'],
            ]);
        }

        $idempotencyKey = "recon:{$exceptionId}:correction";

        $existing = $this->repository->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null && $existing->status === CorrectionRequestStatus::PendingApproval) {
            return $existing;
        }

        $exception = DB::connection('payment')
            ->table('payment.reconciliation_exceptions')
            ->where('id', $exceptionId)
            ->first();

        $beforeState = $exception ? ['status' => $exception->status] : null;

        $record = $this->repository->create([
            'id'                 => (string) Uuid::uuid7(),
            'exception_id'       => $exceptionId,
            'idempotency_key'    => $idempotencyKey,
            'correction_type'    => $correctionType,
            'description'        => $description,
            'evidence_note'      => $evidenceNote,
            'evidence_reference' => $evidenceReference,
            'debit_account'      => $debitAccount,
            'credit_account'     => $creditAccount,
            'amount_minor'       => $amountMinor,
            'currency_code'      => $currencyCode,
            'status'             => CorrectionRequestStatus::PendingApproval->value,
            'requested_by'       => $requestedBy,
        ]);

        $this->audit->record(new AuditEventData(
            action:       'payment.reconciliation.correction_submitted',
            actorType:    'user',
            resourceType: 'reconciliation_correction',
            correlationId: $record->id,
            outcome:      'success',
            actorId:      $requestedBy,
            resourceId:   $record->id,
            beforeState:  $beforeState,
            afterState:   ['status' => CorrectionRequestStatus::PendingApproval->value],
        ));

        $this->metrics->increment('payment.reconciliation.corrections_submitted', [
            'correction_type' => $correctionType,
        ]);

        return $record;
    }

    public function approve(string $correctionId, string $reviewerId): ReconciliationCorrectionRequestModel
    {
        $request = $this->repository->findById($correctionId);
        if ($request === null) {
            throw new \RuntimeException("Correction request not found: {$correctionId}");
        }

        if (!$request->status->canBeApproved()) {
            throw CorrectionApprovalException::notInPendingState($request->status->value);
        }

        if ($reviewerId === $request->requested_by) {
            throw CorrectionApprovalException::selfApprovalNotPermitted();
        }

        // Post the correcting ledger entry
        $ledgerEntryId = $this->ledger->postEntry([
            'idempotency_key'       => $request->idempotency_key,
            'entry_type'            => 'debit',
            'currency_code'         => $request->currency_code,
            'transaction_reference' => $correctionId,
            'correlation_id'        => $correctionId,
            'description'           => $request->description,
            'lines'                 => [
                [
                    'ledger_account_reference' => $request->debit_account ?? 'reconciliation.suspense',
                    'direction'                => 'debit',
                    'amount'                   => $request->amount_minor,
                ],
                [
                    'ledger_account_reference' => $request->credit_account ?? 'reconciliation.suspense',
                    'direction'                => 'credit',
                    'amount'                   => $request->amount_minor,
                ],
            ],
        ]);

        $postedAt = now();

        $this->repository->updateStatus($correctionId, CorrectionRequestStatus::Posted, [
            'ledger_entry_id' => $ledgerEntryId,
            'reviewed_by'     => $reviewerId,
            'reviewed_at'     => $postedAt,
            'posted_at'       => $postedAt,
        ]);

        DB::connection('platform')->transaction(function () use ($request, $ledgerEntryId, $reviewerId, $postedAt): void {
            $this->outbox->publish(new CorrectionPostedEvent(
                correctionId:   $request->id,
                exceptionId:    $request->exception_id,
                correctionType: $request->correction_type,
                amountMinor:    $request->amount_minor,
                currencyCode:   $request->currency_code,
                ledgerEntryId:  $ledgerEntryId,
                reviewedBy:     $reviewerId,
                occurredAt:     $postedAt->toIso8601String(),
            ));
        });

        $this->audit->record(new AuditEventData(
            action:       'payment.reconciliation.correction_approved',
            actorType:    'user',
            resourceType: 'reconciliation_correction',
            correlationId: $correctionId,
            outcome:      'success',
            actorId:      $reviewerId,
            resourceId:   $correctionId,
            afterState:   [
                'status'          => CorrectionRequestStatus::Posted->value,
                'ledger_entry_id' => $ledgerEntryId,
            ],
        ));

        $this->metrics->histogram(
            'payment.reconciliation.correction_approval_lag',
            $request->created_at?->diffInSeconds($postedAt) ?? 0,
            ['correction_type' => $request->correction_type]
        );

        return $this->repository->findById($correctionId) ?? $request;
    }

    public function reject(string $correctionId, string $reviewerId, string $reason): ReconciliationCorrectionRequestModel
    {
        $request = $this->repository->findById($correctionId);
        if ($request === null) {
            throw new \RuntimeException("Correction request not found: {$correctionId}");
        }

        if (!$request->status->canBeRejected()) {
            throw CorrectionApprovalException::notInPendingState($request->status->value);
        }

        $this->repository->updateStatus($correctionId, CorrectionRequestStatus::Rejected, [
            'reviewed_by'      => $reviewerId,
            'reviewed_at'      => now(),
            'rejection_reason' => $reason,
        ]);

        $this->audit->record(new AuditEventData(
            action:       'payment.reconciliation.correction_rejected',
            actorType:    'user',
            resourceType: 'reconciliation_correction',
            correlationId: $correctionId,
            outcome:      'success',
            actorId:      $reviewerId,
            resourceId:   $correctionId,
            afterState:   ['status' => CorrectionRequestStatus::Rejected->value, 'reason' => $reason],
        ));

        return $this->repository->findById($correctionId) ?? $request;
    }
}
