<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

interface IdempotencyServiceInterface
{
    public function findCompletedResponse(string $idempotencyKey, string $actorId): ?array;

    public function acquireLock(string $idempotencyKey, string $actorId, string $requestHash, string $httpMethod, string $httpPath, string $correlationId): IdempotencyLock;

    public function recordResponse(IdempotencyLock $lock, int $statusCode, array $responseBody): void;

    public function recordFailure(IdempotencyLock $lock, \Throwable $e): void;
}
