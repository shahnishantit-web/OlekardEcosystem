<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Application\Services;

use App\Modules\Kyc\Domain\Contracts\KycCaseRepositoryInterface;
use App\Modules\Kyc\Domain\Enums\KycCaseStatus;
use App\Modules\Kyc\Domain\Events\KycCaseApprovedEvent;
use App\Modules\Kyc\Domain\Events\KycCaseExpiredEvent;
use App\Modules\Kyc\Domain\Events\KycCaseReverificationTriggeredEvent;
use App\Modules\Kyc\Domain\Exceptions\KycAlreadyApprovedException;
use App\Modules\Kyc\Domain\Exceptions\KycCaseAlreadyOpenException;
use App\Modules\Kyc\Domain\Exceptions\KycCaseIncompleteException;
use App\Modules\Kyc\Domain\Exceptions\KycCaseNotEditableException;
use App\Modules\Kyc\Domain\Exceptions\KycDuplicateIdentityException;
use App\Modules\Kyc\Domain\Exceptions\KycInvalidStatusTransitionException;
use App\Modules\Kyc\Domain\Services\KycDuplicateIdentityChecker;
use App\Modules\Kyc\Domain\Services\KycStateMachine;
use App\Modules\Kyc\Infrastructure\Jobs\PerformAutomatedVerificationJob;
use App\Modules\Kyc\Infrastructure\Models\KycCaseModel;
use App\Modules\Platform\Application\Services\AuditService;
use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

final class KycCaseService
{
    public function __construct(
        private readonly KycCaseRepositoryInterface  $repository,
        private readonly KycStateMachine             $stateMachine,
        private readonly KycDuplicateIdentityChecker $duplicateChecker,
        private readonly AuditService                $audit,
        private readonly OutboxEventPublisher        $outbox,
        private readonly ConfigurationServiceInterface $config,
        private readonly MetricsCollectorInterface   $metrics,
    ) {}

    public function startCase(
        string $userId,
        string $idempotencyKey,
        string $correlationId,
        string $actorType = 'CUSTOMER',
        ?string $ipAddress = null,
    ): KycCaseModel {
        // Check for existing open case
        $openCase = $this->repository->findOpenCaseForUser($userId);
        if ($openCase !== null) {
            throw new KycCaseAlreadyOpenException();
        }

        // Check for approved case (cannot start again while approved)
        $approvedCase = KycCaseModel::on('kyc')
            ->where('user_id', $userId)
            ->where('status', KycCaseStatus::Approved->value)
            ->first();
        if ($approvedCase !== null) {
            throw new KycAlreadyApprovedException();
        }
        // Note: expired case is NOT open per BR-E05-001 — allow start without error (EC-08)

        $id = (string) Uuid::uuid7();

        DB::connection('kyc')->transaction(function () use ($id, $userId, $idempotencyKey, $correlationId, $actorType, $ipAddress): void {
            DB::connection('kyc')->table('kyc.kyc_cases')->insert([
                'id'              => $id,
                'user_id'         => $userId,
                'status'          => KycCaseStatus::Initiated->value,
                'attempt_number'  => 1,
                'idempotency_key' => $idempotencyKey,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            $this->audit->record(new AuditEventData(
                action:       'kyc.case.initiated',
                actorType:    $actorType,
                resourceType: 'KycCase',
                correlationId: $correlationId,
                outcome:      'success',
                actorId:      $userId,
                resourceId:   $id,
                ipAddress:    $ipAddress,
            ));
        });

        $this->metrics->increment('kyc.case.initiated', ['actor_type' => $actorType]);
        Log::info('kyc.case.initiated', ['case_id' => $id, 'user_id' => $userId]);

        return $this->repository->findById($id);
    }

    public function capturePersonalInformation(
        string $caseId,
        string $actorId,
        array $data,
        string $correlationId,
        string $actorType = 'CUSTOMER',
        ?string $ipAddress = null,
    ): void {
        $case = $this->repository->findByIdForActor($caseId, $actorId);
        if ($case === null) {
            abort(404);
        }

        if (!$case->status->isEditable()) {
            throw new KycCaseNotEditableException();
        }

        $nationalIdHash = \App\Modules\Platform\Infrastructure\Security\PiiMasker::hashNationalId(
            $data['national_id_number']
        );

        if ($this->duplicateChecker->hasApprovedCaseWithHash($nationalIdHash, $caseId)) {
            $this->audit->record(new AuditEventData(
                action:       'kyc.duplicate-identity.detected',
                actorType:    $actorType,
                resourceType: 'KycCase',
                correlationId: $correlationId,
                outcome:      'failure',
                actorId:      $actorId,
                resourceId:   $caseId,
                ipAddress:    $ipAddress,
            ));
            $this->metrics->increment('kyc.duplicate_identity_detected');
            throw new KycDuplicateIdentityException();
        }

        DB::connection('kyc')->transaction(function () use ($caseId, $actorId, $data, $nationalIdHash, $correlationId, $actorType, $ipAddress): void {
            DB::connection('kyc')->table('kyc.kyc_personal_info')->updateOrInsert(
                ['kyc_case_id' => $caseId],
                [
                    'id'               => (string) Uuid::uuid7(),
                    'kyc_case_id'      => $caseId,
                    'full_legal_name'  => $data['full_legal_name'],
                    'date_of_birth'    => $data['date_of_birth'],
                    'nationality'      => $data['nationality'],
                    'national_id_type' => $data['national_id_type'],
                    'national_id_hash' => $nationalIdHash,
                    'address_line_1'   => $data['address']['line_1'],
                    'address_line_2'   => $data['address']['line_2'] ?? null,
                    'city'             => $data['address']['city'],
                    'state_province'   => $data['address']['state_province'] ?? null,
                    'postal_code'      => $data['address']['postal_code'],
                    'country_code'     => $data['address']['country_code'],
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ],
            );

            $this->audit->record(new AuditEventData(
                action:       'kyc.case.personal-information-captured',
                actorType:    $actorType,
                resourceType: 'KycCase',
                correlationId: $correlationId,
                outcome:      'success',
                actorId:      $actorId,
                resourceId:   $caseId,
                ipAddress:    $ipAddress,
            ));
        });
    }

    public function submitCase(
        string $caseId,
        string $actorId,
        string $idempotencyKey,
        string $correlationId,
        string $actorType = 'CUSTOMER',
        ?string $ipAddress = null,
    ): KycCaseModel {
        $case = $this->repository->findByIdForActor($caseId, $actorId);
        if ($case === null) {
            abort(404);
        }

        if (!in_array($case->status, [KycCaseStatus::Initiated, KycCaseStatus::DocumentsSubmitted], true)) {
            throw new KycInvalidStatusTransitionException($case->status, 'submit');
        }

        // Completeness check
        $missing = [];
        $hasPersonalInfo = DB::connection('kyc')
            ->table('kyc.kyc_personal_info')
            ->where('kyc_case_id', $caseId)
            ->exists();

        if (!$hasPersonalInfo) {
            $missing[] = 'personal_information';
        }

        $hasIdentityDoc = DB::connection('kyc')
            ->table('kyc.kyc_documents')
            ->where('kyc_case_id', $caseId)
            ->whereIn('document_type', ['national_id', 'passport', 'drivers_licence'])
            ->where('validation_status', 'valid')
            ->whereNull('deleted_at')
            ->exists();

        if (!$hasIdentityDoc) {
            $missing[] = 'identity_document';
        }

        if (!empty($missing)) {
            throw new KycCaseIncompleteException($missing);
        }

        DB::connection('kyc')->transaction(function () use ($case, $caseId, $correlationId, $actorId, $actorType, $ipAddress): void {
            DB::connection('kyc')->table('kyc.kyc_cases')
                ->where('id', $caseId)
                ->update([
                    'status'       => KycCaseStatus::UnderReview->value,
                    'submitted_at' => now(),
                    'updated_at'   => now(),
                ]);

            // FR-E05-019: audit only — submit is NOT a domain event
            $this->audit->record(new AuditEventData(
                action:       'kyc.case.submitted',
                actorType:    $actorType,
                resourceType: 'KycCase',
                correlationId: $correlationId,
                outcome:      'success',
                actorId:      $actorId,
                resourceId:   $caseId,
                ipAddress:    $ipAddress,
            ));
        });

        // Dispatch auto-verify job if enabled
        $autoVerify = $this->config->getOrDefault('kyc.auto_verify_enabled', false);
        if ($autoVerify === 'true' || $autoVerify === true) {
            PerformAutomatedVerificationJob::dispatch($caseId);
        }

        return $this->repository->findById($caseId);
    }

    public function approveCase(
        string $caseId,
        ?string $reviewerId,
        string $correlationId,
        string $method = 'manual',
        string $actorType = 'ADMIN',
        ?string $ipAddress = null,
    ): KycCaseModel {
        $case = $this->repository->findById($caseId);
        if ($case === null) {
            abort(404);
        }

        if ($case->status !== KycCaseStatus::UnderReview) {
            throw new KycInvalidStatusTransitionException($case->status, 'approve');
        }

        // Compute expires_at from validity_period_days config
        $validityDays = $this->config->getOrDefault('kyc.validity_period_days', null);
        $expiresAt    = null;

        if ($validityDays !== null && $validityDays !== '' && !$this->isTbd('kyc.validity_period_days')) {
            $expiresAt = now()->addDays((int) $validityDays);
        } else {
            Log::warning('kyc.config.tbd_active', ['key' => 'kyc.validity_period_days']);
        }

        $verifiedAt = now();

        DB::connection('kyc')->transaction(function () use ($case, $caseId, $reviewerId, $verifiedAt, $expiresAt, $correlationId, $actorType, $ipAddress, $method): void {
            DB::connection('kyc')->table('kyc.kyc_cases')
                ->where('id', $caseId)
                ->update([
                    'status'      => KycCaseStatus::Approved->value,
                    'verified_at' => $verifiedAt,
                    'expires_at'  => $expiresAt,
                    'reviewed_at' => now(),
                    'reviewer_id' => $reviewerId,
                    'updated_at'  => now(),
                ]);

            $this->audit->record(new AuditEventData(
                action:       'kyc.case.approved',
                actorType:    $actorType,
                resourceType: 'KycCase',
                correlationId: $correlationId,
                outcome:      'success',
                actorId:      $reviewerId,
                resourceId:   $caseId,
                ipAddress:    $ipAddress,
            ));

            $this->outbox->publish(new KycCaseApprovedEvent(
                caseId:             $caseId,
                actorId:            $reviewerId,
                correlationId:      $correlationId,
                userId:             $case->user_id,
                verifiedAt:         $verifiedAt->toIso8601String(),
                expiresAt:          $expiresAt?->toIso8601String(),
                verificationMethod: $method,
                attemptNumber:      $case->attempt_number,
            ));
        });

        $this->metrics->increment('kyc.case.approved', ['method' => $method]);

        return $this->repository->findById($caseId);
    }

    public function rejectCase(
        string $caseId,
        string $reviewerId,
        string $reason,
        string $correlationId,
        string $actorType = 'ADMIN',
        ?string $ipAddress = null,
    ): KycCaseModel {
        if (trim($reason) === '') {
            abort(400, 'REJECTION_REASON_REQUIRED');
        }

        $case = $this->repository->findById($caseId);
        if ($case === null) {
            abort(404);
        }

        if ($case->status !== KycCaseStatus::UnderReview) {
            throw new KycInvalidStatusTransitionException($case->status, 'reject');
        }

        DB::connection('kyc')->transaction(function () use ($caseId, $reviewerId, $reason, $correlationId, $actorType, $ipAddress): void {
            DB::connection('kyc')->table('kyc.kyc_cases')
                ->where('id', $caseId)
                ->update([
                    'status'           => KycCaseStatus::Rejected->value,
                    'rejection_reason' => $reason,
                    'reviewed_at'      => now(),
                    'reviewer_id'      => $reviewerId,
                    'updated_at'       => now(),
                ]);

            $this->audit->record(new AuditEventData(
                action:       'kyc.case.rejected',
                actorType:    $actorType,
                resourceType: 'KycCase',
                correlationId: $correlationId,
                outcome:      'success',
                actorId:      $reviewerId,
                resourceId:   $caseId,
                ipAddress:    $ipAddress,
            ));
        });

        $this->metrics->increment('kyc.case.rejected');

        return $this->repository->findById($caseId);
    }

    public function requestAdditionalInfo(
        string $caseId,
        string $reviewerId,
        string $note,
        string $correlationId,
        string $actorType = 'ADMIN',
        ?string $ipAddress = null,
    ): KycCaseModel {
        if (trim($note) === '') {
            abort(400, 'INFO_REQUEST_NOTE_REQUIRED');
        }

        $case = $this->repository->findById($caseId);
        if ($case === null) {
            abort(404);
        }

        if ($case->status !== KycCaseStatus::UnderReview) {
            throw new KycInvalidStatusTransitionException($case->status, 'requestInfo');
        }

        DB::connection('kyc')->transaction(function () use ($caseId, $reviewerId, $note, $correlationId, $actorType, $ipAddress): void {
            DB::connection('kyc')->table('kyc.kyc_cases')
                ->where('id', $caseId)
                ->update([
                    'status'            => KycCaseStatus::NeedsInfo->value,
                    'info_request_note' => $note,
                    'reviewed_at'       => now(),
                    'reviewer_id'       => $reviewerId,
                    'updated_at'        => now(),
                ]);

            $this->audit->record(new AuditEventData(
                action:       'kyc.case.needs-info',
                actorType:    $actorType,
                resourceType: 'KycCase',
                correlationId: $correlationId,
                outcome:      'success',
                actorId:      $reviewerId,
                resourceId:   $caseId,
                ipAddress:    $ipAddress,
            ));
        });

        return $this->repository->findById($caseId);
    }

    public function resubmitCase(
        string $caseId,
        string $actorId,
        string $idempotencyKey,
        string $correlationId,
        string $actorType = 'CUSTOMER',
        ?string $ipAddress = null,
    ): KycCaseModel {
        $case = $this->repository->findByIdForActor($caseId, $actorId);
        if ($case === null) {
            abort(404);
        }

        if (!in_array($case->status, [KycCaseStatus::Rejected, KycCaseStatus::NeedsInfo], true)) {
            throw new KycInvalidStatusTransitionException($case->status, 'resubmit');
        }

        DB::connection('kyc')->transaction(function () use ($case, $caseId, $actorId, $correlationId, $actorType, $ipAddress): void {
            // Soft-delete all active documents
            DB::connection('kyc')->table('kyc.kyc_documents')
                ->where('kyc_case_id', $caseId)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => now()]);

            DB::connection('kyc')->table('kyc.kyc_cases')
                ->where('id', $caseId)
                ->update([
                    'status'            => KycCaseStatus::Initiated->value,
                    'attempt_number'    => $case->attempt_number + 1,
                    'rejection_reason'  => null,
                    'info_request_note' => null,
                    'updated_at'        => now(),
                ]);

            // FR-E05-033: audit only — resubmit is NOT a domain event
            $this->audit->record(new AuditEventData(
                action:       'kyc.case.resubmitted',
                actorType:    $actorType,
                resourceType: 'KycCase',
                correlationId: $correlationId,
                outcome:      'success',
                actorId:      $actorId,
                resourceId:   $caseId,
                ipAddress:    $ipAddress,
            ));
        });

        return $this->repository->findById($caseId);
    }

    public function triggerReverification(
        string $caseId,
        string $actorId,
        string $correlationId,
        string $actorType = 'ADMIN',
        ?string $ipAddress = null,
    ): KycCaseModel {
        $case = $this->repository->findById($caseId);
        if ($case === null) {
            abort(404);
        }

        if (!in_array($case->status, [KycCaseStatus::Approved, KycCaseStatus::Expired], true)) {
            throw new KycInvalidStatusTransitionException($case->status, 'triggerReverification');
        }

        DB::connection('kyc')->transaction(function () use ($case, $caseId, $actorId, $correlationId, $actorType, $ipAddress): void {
            // Soft-delete all active documents
            DB::connection('kyc')->table('kyc.kyc_documents')
                ->where('kyc_case_id', $caseId)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => now()]);

            $newAttempt = $case->attempt_number + 1;

            DB::connection('kyc')->table('kyc.kyc_cases')
                ->where('id', $caseId)
                ->update([
                    'status'         => KycCaseStatus::Initiated->value,
                    'attempt_number' => $newAttempt,
                    'updated_at'     => now(),
                ]);

            $this->audit->record(new AuditEventData(
                action:       'kyc.case.reverification-triggered',
                actorType:    $actorType,
                resourceType: 'KycCase',
                correlationId: $correlationId,
                outcome:      'success',
                actorId:      $actorId,
                resourceId:   $caseId,
                ipAddress:    $ipAddress,
            ));

            $this->outbox->publish(new KycCaseReverificationTriggeredEvent(
                caseId:        $caseId,
                actorId:       $actorId,
                correlationId: $correlationId,
                userId:        $case->user_id,
                triggeredBy:   $actorId,
                attemptNumber: $newAttempt,
            ));
        });

        return $this->repository->findById($caseId);
    }

    public function detectAndExpireCases(int $batchSize = 100, bool $dryRun = false): array
    {
        // TBD guard: if validity_period_days is TBD, expiry detection cannot run
        if ($this->isTbd('kyc.validity_period_days')) {
            Log::warning('kyc.config.tbd_active', ['key' => 'kyc.validity_period_days', 'action' => 'detect-expired skipped']);
            return ['expired' => 0, 'skipped' => 0, 'errors' => 0];
        }

        $expired = 0;
        $skipped = 0;
        $errors  = 0;

        $cases = DB::connection('kyc')
            ->table('kyc.kyc_cases')
            ->where('status', KycCaseStatus::Approved->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->limit($batchSize)
            ->get(['id', 'user_id', 'expires_at']);

        foreach ($cases as $case) {
            if ($dryRun) {
                $skipped++;
                continue;
            }

            try {
                $expiredAt = now()->toIso8601String();

                DB::connection('kyc')->transaction(function () use ($case, $expiredAt): void {
                    DB::connection('kyc')->table('kyc.kyc_cases')
                        ->where('id', $case->id)
                        ->where('status', KycCaseStatus::Approved->value)
                        ->update([
                            'status'     => KycCaseStatus::Expired->value,
                            'updated_at' => now(),
                        ]);

                    $this->audit->record(new AuditEventData(
                        action:       'kyc.case.expired',
                        actorType:    'system',
                        resourceType: 'KycCase',
                        correlationId: (string) Uuid::uuid7(),
                        outcome:      'success',
                        actorId:      null,
                        resourceId:   $case->id,
                    ));

                    $this->outbox->publish(new KycCaseExpiredEvent(
                        caseId:       $case->id,
                        actorId:      null,
                        correlationId: (string) Uuid::uuid7(),
                        userId:       $case->user_id,
                        expiredAt:    $expiredAt,
                        wasExpiresAt: $case->expires_at,
                    ));
                });

                $expired++;
            } catch (\Throwable $e) {
                Log::error('kyc.expiry.error', ['case_id' => $case->id, 'error' => $e->getMessage()]);
                $errors++;
            }
        }

        return ['expired' => $expired, 'skipped' => $skipped, 'errors' => $errors];
    }

    private function isTbd(string $key): bool
    {
        $row = DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', $key)
            ->first(['is_tbd', 'config_value']);

        return $row !== null && ($row->is_tbd === true || $row->is_tbd === 1 || $row->is_tbd === '1');
    }
}
