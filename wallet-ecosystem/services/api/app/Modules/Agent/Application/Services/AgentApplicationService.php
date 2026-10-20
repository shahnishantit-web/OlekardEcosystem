<?php

declare(strict_types=1);

namespace App\Modules\Agent\Application\Services;

use App\Modules\Agent\Domain\Contracts\AgentKycProviderContract;
use App\Modules\Agent\Domain\Enums\AgentType;
use App\Modules\Agent\Domain\Enums\ApplicationStatus;
use App\Modules\Agent\Domain\Enums\DocumentStatus;
use App\Modules\Agent\Domain\Enums\DocumentType;
use App\Modules\Agent\Domain\Events\AgentApplicationBusinessInfoUpdatedEvent;
use App\Modules\Agent\Domain\Events\AgentApplicationCreatedEvent;
use App\Modules\Agent\Domain\Events\AgentApplicationKycBypassedEvent;
use App\Modules\Agent\Domain\Events\AgentApplicationKycInitiatedEvent;
use App\Modules\Agent\Domain\Events\AgentApplicationPersonalInfoUpdatedEvent;
use App\Modules\Agent\Domain\Exceptions\ApplicationIncompleteException;
use App\Modules\Agent\Domain\Exceptions\ApplicationNotEditableException;
use App\Modules\Agent\Domain\Exceptions\DuplicateBusinessNameException;
use App\Modules\Agent\Domain\Exceptions\UnauthorizedCustomerAccessException;
use App\Modules\Agent\Domain\ValueObjects\AgentKycPayload;
use App\Modules\Agent\Infrastructure\Models\AgentApplicationModel;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class AgentApplicationService
{
    public function __construct(
        private readonly IdempotencyServiceInterface $idempotency,
        private readonly EventPublisherInterface     $events,
        private readonly AgentKycProviderContract    $kycProvider,
        private readonly ConfigurationServiceInterface $config,
    ) {}

    public function create(
        string    $userId,
        AgentType $agentType,
        string    $idempotencyKey,
        string    $correlationId,
    ): AgentApplicationModel {
        $storeKey = "agent_application_create:{$userId}:{$idempotencyKey}";

        $cached = $this->idempotency->findCompletedResponse($storeKey, $userId);
        if ($cached !== null) {
            $applicationId = $cached['body']['application_id'] ?? null;
            if ($applicationId !== null) {
                $existing = AgentApplicationModel::on('platform')->find($applicationId);
                if ($existing !== null) {
                    return $existing;
                }
            }
        }

        $lock = $this->idempotency->acquireLock(
            $storeKey,
            $userId,
            hash('sha256', "{$userId}:{$idempotencyKey}"),
            'POST',
            '/v1/agent-applications',
            $correlationId,
        );

        try {
            return DB::connection('platform')->transaction(
                function () use ($userId, $agentType, $idempotencyKey, $correlationId, $lock): AgentApplicationModel {
                    $id = (string) Uuid::uuid7();

                    DB::connection('platform')->table('agent.agent_applications')->insert([
                        'id'              => $id,
                        'user_id'         => $userId,
                        'agent_type'      => $agentType->value,
                        'status'          => ApplicationStatus::Draft->value,
                        'correlation_id'  => $correlationId,
                        'idempotency_key' => $idempotencyKey,
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ]);

                    $this->events->publish(new AgentApplicationCreatedEvent(
                        applicationId: $id,
                        userId:        $userId,
                        agentType:     $agentType->value,
                        occurredAt:    now()->toIso8601String(),
                        correlationId: $correlationId,
                    ));

                    $this->idempotency->recordResponse($lock, 201, ['application_id' => $id]);

                    $model = AgentApplicationModel::on('platform')->findOrFail($id);
                    $model->wasRecentlyCreated = true;
                    return $model;
                }
            );
        } catch (\Throwable $e) {
            $this->idempotency->recordFailure($lock, $e);
            throw $e;
        }
    }

    public function updatePersonalInfo(
        string $applicationId,
        string $actorId,
        array  $data,
        string $correlationId,
    ): AgentApplicationModel {
        $application = AgentApplicationModel::on('platform')->findOrFail($applicationId);

        if ($application->user_id !== $actorId) {
            throw new UnauthorizedCustomerAccessException();
        }

        if (!$application->isEditable()) {
            throw new ApplicationNotEditableException();
        }

        DB::connection('platform')->transaction(function () use ($application, $actorId, $data, $correlationId): void {
            $merged = array_merge(
                (array) ($application->personal_info ?? []),
                $data,
            );

            $newStatus = $application->status === ApplicationStatus::Draft
                ? ApplicationStatus::InProgress
                : $application->status;

            $application->update([
                'personal_info'   => $merged,
                'status'          => $newStatus->value,
                'last_updated_by' => $actorId,
                'updated_at'      => now(),
            ]);

            $this->events->publish(new AgentApplicationPersonalInfoUpdatedEvent(
                applicationId: $application->id,
                actorId:       $actorId,
                occurredAt:    now()->toIso8601String(),
                correlationId: $correlationId,
            ));
        });

        return $application->fresh();
    }

    public function updateBusinessInfo(
        string $applicationId,
        string $actorId,
        array  $data,
        string $correlationId,
    ): AgentApplicationModel {
        $application = AgentApplicationModel::on('platform')->findOrFail($applicationId);

        if ($application->user_id !== $actorId) {
            throw new UnauthorizedCustomerAccessException();
        }

        if (!$application->isEditable()) {
            throw new ApplicationNotEditableException();
        }

        $tradingName = $data['trading_name'] ?? null;

        if ($tradingName !== null && $tradingName !== '') {
            $duplicate = DB::connection('platform')
                ->table('agent.agent_applications')
                ->where('status', ApplicationStatus::Approved->value)
                ->where('id', '!=', $applicationId)
                ->whereRaw("business_info->>'trading_name' = ?", [$tradingName])
                ->exists();

            if ($duplicate) {
                throw new DuplicateBusinessNameException($tradingName);
            }
        }

        DB::connection('platform')->transaction(function () use ($application, $actorId, $data, $correlationId): void {
            $merged = array_merge(
                (array) ($application->business_info ?? []),
                $data,
            );

            $application->update([
                'business_info'   => $merged,
                'last_updated_by' => $actorId,
                'updated_at'      => now(),
            ]);

            $this->events->publish(new AgentApplicationBusinessInfoUpdatedEvent(
                applicationId: $application->id,
                actorId:       $actorId,
                occurredAt:    now()->toIso8601String(),
                correlationId: $correlationId,
            ));
        });

        return $application->fresh();
    }

    public function triggerKyc(
        string $applicationId,
        string $actorId,
        string $idempotencyKey,
        string $correlationId,
    ): AgentApplicationModel {
        $application = AgentApplicationModel::on('platform')->findOrFail($applicationId);

        if ($application->user_id !== $actorId) {
            throw new UnauthorizedCustomerAccessException();
        }

        // Idempotency check must precede state validation so that a replay
        // succeeds even when the application has already advanced past the
        // allowed-for-KYC statuses.
        $storeKey = "agent_kyc_trigger:{$applicationId}:{$idempotencyKey}";
        $cached = $this->idempotency->findCompletedResponse($storeKey, $actorId);
        if ($cached !== null) {
            return $application->fresh();
        }

        $allowedForKyc = [ApplicationStatus::Draft, ApplicationStatus::InProgress, ApplicationStatus::Submitted];
        if (!in_array($application->status, $allowedForKyc, true)) {
            throw new ApplicationNotEditableException();
        }

        if ($application->personal_info === null) {
            throw new ApplicationIncompleteException('personal_info');
        }

        $hasNationalId = DB::connection('platform')
            ->table('agent.agent_documents')
            ->where('application_id', $applicationId)
            ->where('document_type', DocumentType::NationalId->value)
            ->whereIn('status', [DocumentStatus::PendingReview->value, DocumentStatus::Accepted->value])
            ->exists();

        if (!$hasNationalId) {
            throw new ApplicationIncompleteException('national_id_document');
        }

        $lock = $this->idempotency->acquireLock(
            $storeKey,
            $actorId,
            hash('sha256', $storeKey),
            'POST',
            "/v1/agent-applications/{$applicationId}/kyc",
            $correlationId,
        );

        $adminBypass = (bool) $this->config->getOrDefault('agent.kyc.admin_bypass', false);

        if ($adminBypass) {
            try {
                DB::connection('platform')->transaction(function () use ($application, $actorId, $correlationId, $lock): void {
                    $application->update([
                        'status'                 => ApplicationStatus::KycPassed->value,
                        'kyc_result'             => 'passed',
                        'kyc_provider_reference' => 'ADMIN_KYC_BYPASS',
                        'kyc_initiated_at'       => now(),
                    ]);
                    $this->events->publish(new AgentApplicationKycBypassedEvent(
                        applicationId: $application->id,
                        adminActorId:  $actorId,
                        occurredAt:    now()->toIso8601String(),
                        correlationId: $correlationId,
                    ));
                    $this->idempotency->recordResponse($lock, 202, ['application_id' => $application->id]);
                });
            } catch (\Throwable $e) {
                $this->idempotency->recordFailure($lock, $e);
                throw $e;
            }

            return $application->fresh();
        }

        $pi = (array) ($application->personal_info ?? []);
        $bi = (array) ($application->business_info ?? []);

        $payload = new AgentKycPayload(
            applicationId:              $application->id,
            firstName:                  (string) ($pi['first_name'] ?? ''),
            lastName:                   (string) ($pi['last_name'] ?? ''),
            nationalIdNumber:           (string) ($pi['national_id_number'] ?? ''),
            nationalIdType:             (string) ($pi['national_id_type'] ?? ''),
            dateOfBirth:                (string) ($pi['date_of_birth'] ?? ''),
            phone:                      (string) ($pi['phone'] ?? ''),
            address:                    (array) ($pi['address'] ?? []),
            tradingName:                isset($bi['trading_name']) ? (string) $bi['trading_name'] : null,
            businessRegistrationNumber: isset($bi['registration_number']) ? (string) $bi['registration_number'] : null,
        );

        $applicationAtKycInProgress = null;

        try {
            DB::connection('platform')->transaction(function () use ($application, $correlationId, $lock, &$applicationAtKycInProgress): void {
                $application->update([
                    'status'           => ApplicationStatus::KycInProgress->value,
                    'kyc_initiated_at' => now(),
                ]);
                $this->events->publish(new AgentApplicationKycInitiatedEvent(
                    applicationId: $application->id,
                    occurredAt:    now()->toIso8601String(),
                    correlationId: $correlationId,
                ));
                $this->idempotency->recordResponse($lock, 202, ['application_id' => $application->id]);
                $applicationAtKycInProgress = $application->fresh();
            });
        } catch (\Throwable $e) {
            $this->idempotency->recordFailure($lock, $e);
            throw $e;
        }

        try {
            $result = $this->kycProvider->verify($payload);

            DB::connection('platform')->transaction(function () use ($application, $result): void {
                $application->update([
                    'status'                 => $result->passed
                        ? ApplicationStatus::KycPassed->value
                        : ApplicationStatus::KycFailed->value,
                    'kyc_result'             => $result->passed ? 'passed' : 'failed',
                    'kyc_provider_reference' => $result->reference,
                    'kyc_failure_reason'     => $result->passed ? null : $result->failureReason,
                ]);
            });
        } catch (\Throwable) {
            // Provider unavailable — leave application in kyc_in_progress for retry
        }

        return $applicationAtKycInProgress;
    }
}
