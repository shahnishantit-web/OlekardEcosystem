<?php

declare(strict_types=1);

namespace App\Modules\Integration\Application\Services;

use App\Modules\Integration\Application\DTOs\CreateIntegrationData;
use App\Modules\Integration\Application\DTOs\UpdateIntegrationData;
use App\Modules\Integration\Domain\Contracts\IntegrationRegistryInterface;
use App\Modules\Integration\Domain\Contracts\IntegrationRepositoryInterface;
use App\Modules\Integration\Domain\Enums\IntegrationStatus;
use App\Modules\Integration\Domain\Events\IntegrationRegisteredEvent;
use App\Modules\Integration\Domain\Events\IntegrationStatusChangedEvent;
use App\Modules\Integration\Domain\Exceptions\IntegrationNotFoundException;
use App\Modules\Integration\Infrastructure\Models\IntegrationModel;
use App\Modules\Integration\Infrastructure\Models\ProviderCallLogModel;
use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class IntegrationApplicationService
{
    public function __construct(
        private readonly IntegrationRepositoryInterface $repository,
        private readonly IntegrationRegistryInterface $registry,
        private readonly AuditServiceInterface $audit,
        private readonly OutboxEventPublisher $outbox,
    ) {}

    public function register(CreateIntegrationData $data, string $actorUserId, string $correlationId): IntegrationModel
    {
        $existing = IntegrationModel::where('provider_type', $data->providerType->value)
            ->where('provider_slug', $data->providerSlug)
            ->exists();

        if ($existing) {
            throw new ConflictHttpException("Integration with provider_slug '{$data->providerSlug}' already exists for this provider type.");
        }

        $model = DB::connection('platform')->transaction(function () use ($data, $actorUserId, $correlationId): IntegrationModel {
            $integration = new IntegrationModel;
            $integration->id = (string) Uuid::uuid7();
            if ($data->isDefault) {
                $this->repository->demoteDefaultsForType($data->providerType, $integration->id);
            }

            $integration->provider_type = $data->providerType;
            $integration->provider_slug = $data->providerSlug;
            $integration->display_name = $data->displayName;
            $integration->credentials_reference = $data->credentialsReference;
            $integration->endpoint_override = $data->endpointOverride;
            $integration->status = IntegrationStatus::Active;
            $integration->is_default = $data->isDefault;
            $integration->configuration = $data->configuration;
            $integration->created_by_user_id = $actorUserId;

            $this->repository->save($integration);

            $this->outbox->publish(new IntegrationRegisteredEvent(
                $integration->id,
                $data->providerType->value,
                $data->providerSlug,
                $correlationId,
            ));

            return $integration;
        });

        $this->registry->invalidate($data->providerType);

        $this->audit->record(new AuditEventData(
            action: 'integration.provider.registered',
            actorType: 'user',
            resourceType: 'integrations',
            correlationId: $correlationId,
            outcome: 'success',
            actorId: $actorUserId,
            resourceId: $model->id,
            afterState: ['provider_type' => $data->providerType->value, 'provider_slug' => $data->providerSlug],
        ));

        return $model;
    }

    public function update(string $id, UpdateIntegrationData $data, string $actorUserId, string $correlationId): IntegrationModel
    {
        $model = $this->repository->findById($id);

        if ($model === null) {
            throw IntegrationNotFoundException::forId($id);
        }

        $before = ['display_name' => $model->display_name, 'credentials_reference' => $model->credentials_reference];

        if ($data->displayName !== null) {
            $model->display_name = $data->displayName;
        }
        if ($data->credentialsReference !== null) {
            $model->credentials_reference = $data->credentialsReference;
        }
        if ($data->endpointOverride !== null) {
            $model->endpoint_override = $data->endpointOverride;
        }
        if ($data->configuration !== null) {
            $model->configuration = $data->configuration;
        }

        $this->repository->save($model);
        $this->registry->invalidate($model->provider_type);

        $this->audit->record(new AuditEventData(
            action: 'integration.provider.updated',
            actorType: 'user',
            resourceType: 'integrations',
            correlationId: $correlationId,
            outcome: 'success',
            actorId: $actorUserId,
            resourceId: $id,
            beforeState: $before,
            afterState: ['display_name' => $model->display_name, 'credentials_reference' => $model->credentials_reference],
        ));

        return $model;
    }

    public function updateStatus(string $id, IntegrationStatus $status, string $actorUserId, string $correlationId): IntegrationModel
    {
        $model = $this->repository->findById($id);

        if ($model === null) {
            throw IntegrationNotFoundException::forId($id);
        }

        $previousStatus = $model->status->value;

        DB::connection('platform')->transaction(function () use ($id, $status, $model, $previousStatus, $correlationId): void {
            $this->repository->updateStatus($id, $status);
            $this->outbox->publish(new IntegrationStatusChangedEvent(
                $id,
                $model->provider_type->value,
                $previousStatus,
                $status->value,
                $correlationId,
            ));
        });

        $model = $this->repository->findById($id) ?? $model;
        $this->registry->invalidate($model->provider_type);

        $this->audit->record(new AuditEventData(
            action: 'integration.provider.status_changed',
            actorType: 'user',
            resourceType: 'integrations',
            correlationId: $correlationId,
            outcome: 'success',
            actorId: $actorUserId,
            resourceId: $id,
            beforeState: ['status' => $previousStatus],
            afterState: ['status' => $status->value],
        ));

        return $model;
    }

    public function setDefault(string $id, string $actorUserId, string $correlationId): IntegrationModel
    {
        $model = $this->repository->findById($id);

        if ($model === null) {
            throw IntegrationNotFoundException::forId($id);
        }

        DB::connection('platform')->transaction(function () use ($model): void {
            $this->repository->demoteDefaultsForType($model->provider_type, $model->id);
            $model->is_default = true;
            $this->repository->save($model);
        });

        $this->registry->invalidate($model->provider_type);

        $this->audit->record(new AuditEventData(
            action: 'integration.provider.set_default',
            actorType: 'user',
            resourceType: 'integrations',
            correlationId: $correlationId,
            outcome: 'success',
            actorId: $actorUserId,
            resourceId: $id,
            afterState: ['is_default' => true, 'provider_type' => $model->provider_type->value],
        ));

        return $model;
    }

    public function list(array $filters = []): Collection
    {
        return $this->repository->all($filters);
    }

    public function get(string $id): IntegrationModel
    {
        $model = $this->repository->findById($id);

        if ($model === null) {
            throw IntegrationNotFoundException::forId($id);
        }

        return $model;
    }

    public function logCall(string $integrationId, array $callData, string $correlationId): ProviderCallLogModel
    {
        $log = new ProviderCallLogModel;
        $log->id = (string) Uuid::uuid7();
        $log->integration_id = $integrationId;
        $log->correlation_id = $callData['correlation_id'] ?? $correlationId;
        $log->provider_reference = $callData['provider_reference'] ?? null;
        $log->request_summary = $callData['request_summary'] ?? null;
        $log->response_code = $callData['response_code'] ?? null;
        $log->outcome = $callData['outcome'];
        $log->retry_eligible = $callData['retry_eligible'] ?? false;
        $log->elapsed_ms = $callData['elapsed_ms'] ?? null;
        $log->created_at = CarbonImmutable::now();
        $log->save();

        return $log;
    }
}
