<?php

declare(strict_types=1);

namespace App\Modules\Integration\Application\Services;

use App\Modules\Integration\Application\DTOs\CreateApiClientData;
use App\Modules\Integration\Domain\Contracts\ApiClientRepositoryInterface;
use App\Modules\Integration\Domain\Enums\ApiClientStatus;
use App\Modules\Integration\Domain\Events\ApiClientCreatedEvent;
use App\Modules\Integration\Domain\Events\ApiClientSecretRotatedEvent;
use App\Modules\Integration\Domain\Events\ApiClientStatusChangedEvent;
use App\Modules\Integration\Domain\Exceptions\ApiClientNotFoundException;
use App\Modules\Integration\Infrastructure\Models\ApiClientKeyRotationModel;
use App\Modules\Integration\Infrastructure\Models\ApiClientModel;
use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Security\Domain\ValueObjects\SecurityAuditActions;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class ApiClientApplicationService
{
    public function __construct(
        private readonly ApiClientRepositoryInterface $repository,
        private readonly AuditServiceInterface $audit,
        private readonly OutboxEventPublisher $outbox,
    ) {}

    public function create(CreateApiClientData $data, string $actorUserId, string $correlationId): array
    {
        $rawKey = bin2hex(random_bytes(16)); // 32 hex chars
        $rawSecret = bin2hex(random_bytes(32)); // 64 hex chars

        $client = new ApiClientModel;
        $client->id = (string) Uuid::uuid7();
        $client->name = $data->name;
        $client->integration_id = $data->integrationId;
        $client->ip_whitelist = $data->ipWhitelist;
        $client->api_key = $rawKey;
        $client->secret_hash = password_hash($rawSecret, PASSWORD_BCRYPT);
        $client->status = ApiClientStatus::Active;
        $client->permissions = $data->permissions;
        $client->rate_limit_per_minute = $data->rateLimitPerMinute;
        $client->created_by_user_id = $actorUserId;

        DB::connection('platform')->transaction(function () use ($client, $correlationId): void {
            $this->repository->save($client);
            $this->outbox->publish(new ApiClientCreatedEvent($client->id, $client->name, $correlationId));
        });

        $this->audit->record(new AuditEventData(
            action: 'integration.api_client.created',
            actorType: 'user',
            resourceType: 'api_clients',
            correlationId: $correlationId,
            outcome: 'success',
            actorId: $actorUserId,
            resourceId: $client->id,
            afterState: ['name' => $client->name, 'permissions' => $client->permissions],
        ));

        return ['client' => $client, 'raw_secret' => $rawSecret];
    }

    public function rotateSecret(string $clientId, string $actorUserId, string $correlationId, ?string $reason = null): array
    {
        return DB::connection('platform')->transaction(function () use ($clientId, $actorUserId, $correlationId, $reason) {
            $client = ApiClientModel::whereKey($clientId)->lockForUpdate()->first();

            if ($client === null) {
                throw ApiClientNotFoundException::forId($clientId);
            }

            abort_if($client->revoked_at !== null, 409, 'Revoked credentials cannot be changed.');

            $rawSecret = bin2hex(random_bytes(32));
            $oldKeyPrefix = substr($client->api_key, 0, 8);
            $oldSecretHash = $client->secret_hash;

            DB::connection('platform')->transaction(function () use ($client, $rawSecret, $oldKeyPrefix, $actorUserId, $reason, $correlationId): void {
                $client->secret_hash = password_hash($rawSecret, PASSWORD_BCRYPT);
                $this->repository->save($client);

                $rotation = new ApiClientKeyRotationModel;
                $rotation->id = (string) Uuid::uuid7();
                $rotation->api_client_id = $client->id;
                $rotation->old_key_prefix = $oldKeyPrefix;
                $rotation->rotated_by_user_id = $actorUserId;
                $rotation->rotated_at = CarbonImmutable::now();
                $rotation->reason = $reason;
                $rotation->save();

                $this->outbox->publish(new ApiClientSecretRotatedEvent($client->id, $oldKeyPrefix, $correlationId));
            });

            $this->audit->record(new AuditEventData(
                action: 'integration.api_client.secret_rotated',
                actorType: 'user',
                resourceType: 'api_clients',
                correlationId: $correlationId,
                outcome: 'success',
                actorId: $actorUserId,
                resourceId: $client->id,
                beforeState: ['key_prefix' => $oldKeyPrefix],
                afterState: ['key_prefix' => $oldKeyPrefix, 'rotated' => true],
            ));

            return ['client' => $client, 'raw_secret' => $rawSecret];
        });
    }

    public function updateStatus(string $clientId, ApiClientStatus $status, string $actorUserId, string $correlationId): ApiClientModel
    {
        return DB::connection('platform')->transaction(function () use ($clientId, $status, $actorUserId, $correlationId) {
            $client = ApiClientModel::whereKey($clientId)->lockForUpdate()->first();

            if ($client === null) {
                throw ApiClientNotFoundException::forId($clientId);
            }

            abort_if($client->revoked_at !== null, 409, 'Revoked credentials cannot be changed.');

            $previousStatus = $client->status->value;

            DB::connection('platform')->transaction(function () use ($clientId, $status, $previousStatus, $correlationId): void {
                $this->repository->updateStatus($clientId, $status);
                $this->outbox->publish(new ApiClientStatusChangedEvent($clientId, $previousStatus, $status->value, $correlationId));
            });

            $client->status = $status;

            $this->audit->record(new AuditEventData(
                action: 'integration.api_client.status_changed',
                actorType: 'user',
                resourceType: 'api_clients',
                correlationId: $correlationId,
                outcome: 'success',
                actorId: $actorUserId,
                resourceId: $clientId,
                beforeState: ['status' => $previousStatus],
                afterState: ['status' => $status->value],
            ));

            return $client;
        });
    }

    public function updateRateLimit(
        string $clientId,
        int $newRateLimitPerMinute,
        string $actorUserId,
        string $correlationId,
        string $sourceIp = '',
    ): ApiClientModel {
        $client = $this->repository->findById($clientId);

        if ($client === null) {
            throw ApiClientNotFoundException::forId($clientId);
        }

        $oldLimit = $client->rate_limit_per_minute;
        $client->rate_limit_per_minute = $newRateLimitPerMinute;
        $this->repository->save($client);

        $this->audit->record(new AuditEventData(
            action: SecurityAuditActions::CONFIG_RATE_LIMIT_CHANGED,
            actorType: 'user',
            resourceType: 'integration.api_client',
            correlationId: $correlationId,
            outcome: 'success',
            actorId: $actorUserId,
            resourceId: $clientId,
            beforeState: ['rate_limit_per_minute' => $oldLimit],
            afterState: ['rate_limit_per_minute' => $newRateLimitPerMinute],
            ipAddress: $sourceIp,
        ));

        return $client;
    }

    public function list(int $perPage = 20): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage);
    }

    public function get(string $clientId): ApiClientModel
    {
        $client = $this->repository->findById($clientId);

        if ($client === null) {
            throw ApiClientNotFoundException::forId($clientId);
        }

        return $client;
    }
}
