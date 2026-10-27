<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Infrastructure\Models\PaymentIdempotencyRecordModel;

interface PaymentIdempotencyRepositoryInterface
{
    /**
     * Find a non-expired idempotency record by actor, scope, and key.
     */
    public function findRecord(
        string $actorId,
        string $scope,
        string $idempotencyKey,
    ): ?PaymentIdempotencyRecordModel;

    /**
     * Persist a new idempotency record with the given response data.
     * Uses INSERT OR IGNORE — safe to call even if the record already exists.
     */
    public function saveRecord(
        string $actorId,
        string $scope,
        string $idempotencyKey,
        string $requestHash,
        int    $responseStatus,
        array  $responseBody,
    ): void;

    /**
     * Delete all records whose expires_at is in the past.
     *
     * @return int rows deleted
     */
    public function pruneExpired(): int;
}
