<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Repositories;

use App\Modules\Payment\Domain\Contracts\PaymentIdempotencyRepositoryInterface;
use App\Modules\Payment\Infrastructure\Models\PaymentIdempotencyRecordModel;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use Ramsey\Uuid\Uuid;

final class EloquentPaymentIdempotencyRepository implements PaymentIdempotencyRepositoryInterface
{
    public function __construct(
        private readonly ConfigurationServiceInterface $config,
    ) {
    }

    public function findRecord(
        string $actorId,
        string $scope,
        string $idempotencyKey,
    ): ?PaymentIdempotencyRecordModel {
        return PaymentIdempotencyRecordModel::query()
            ->where('actor_id', $actorId)
            ->where('scope', $scope)
            ->where('idempotency_key', $idempotencyKey)
            ->where('expires_at', '>', now())
            ->first();
    }

    public function saveRecord(
        string $actorId,
        string $scope,
        string $idempotencyKey,
        string $requestHash,
        int    $responseStatus,
        array  $responseBody,
    ): void {
        $ttlDays   = (int) ($this->config->getOrDefault('payment.idempotency_ttl_days', 90) ?? 90);
        $expiresAt = now()->addDays($ttlDays);

        PaymentIdempotencyRecordModel::query()->insertOrIgnore([
            'id'              => (string) Uuid::uuid7(),
            'actor_id'        => $actorId,
            'scope'           => $scope,
            'idempotency_key' => $idempotencyKey,
            'request_hash'    => $requestHash,
            'response_status' => $responseStatus,
            'response_body'   => json_encode($responseBody),
            'created_at'      => now(),
            'expires_at'      => $expiresAt,
        ]);
    }

    public function pruneExpired(): int
    {
        return PaymentIdempotencyRecordModel::query()
            ->where('expires_at', '<', now())
            ->delete();
    }
}
