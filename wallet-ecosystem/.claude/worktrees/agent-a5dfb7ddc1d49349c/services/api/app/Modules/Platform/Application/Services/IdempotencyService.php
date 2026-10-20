<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Services;

use App\Exceptions\IdempotencyInProgressException;
use App\Exceptions\IdempotencyKeyMismatchException;
use App\Modules\Platform\Domain\Contracts\IdempotencyLock;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use App\Modules\Platform\Infrastructure\Cache\IdempotencyCacheRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

final class IdempotencyService implements IdempotencyServiceInterface
{
    private const RECORD_TTL_DAYS = 7;
    private const ZERO_UUID = '00000000-0000-0000-0000-000000000000';

    public function __construct(private readonly IdempotencyCacheRepository $cache) {}

    public function findCompletedResponse(string $idempotencyKey, string $actorId): ?array
    {
        // Fast path: Redis cache
        $cached = $this->cache->find($actorId, $idempotencyKey);
        if ($cached !== null) {
            Log::info('idempotency.replay', [
                'actor_id'        => $actorId,
                'idempotency_key' => $idempotencyKey,
                'source'          => 'cache',
            ]);

            return $cached;
        }

        // Slow path: authoritative PostgreSQL
        $record = DB::connection('platform')
            ->table('platform.idempotency_records')
            ->where('actor_id', $actorId)
            ->where('idempotency_key', $idempotencyKey)
            ->where('status', 'completed')
            ->first(['response_status', 'response_body']);

        if ($record === null) {
            return null;
        }

        $response = [
            'status' => $record->response_status,
            'body'   => json_decode($record->response_body, true),
        ];

        $this->cache->store($actorId, $idempotencyKey, $response, self::RECORD_TTL_DAYS * 86400);

        Log::info('idempotency.replay', [
            'actor_id'        => $actorId,
            'idempotency_key' => $idempotencyKey,
            'source'          => 'database',
        ]);

        return $response;
    }

    public function acquireLock(
        string $idempotencyKey,
        string $actorId,
        string $requestHash,
        string $httpMethod,
        string $httpPath,
        string $correlationId,
    ): IdempotencyLock {
        $id = (string) Uuid::uuid7();

        // INSERT ON CONFLICT DO NOTHING — racing insert
        $inserted = DB::connection('platform')->affectingStatement(
            "INSERT INTO platform.idempotency_records
             (id, idempotency_key, actor_id, actor_type, request_hash, http_method, http_path,
              status, attempt_count, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (actor_id, idempotency_key) DO NOTHING",
            [
                $id,
                $idempotencyKey,
                $actorId ?: self::ZERO_UUID,
                'USER',
                $requestHash,
                $httpMethod,
                $httpPath,
                'processing',
                1,
                now(),
                now()->addDays(self::RECORD_TTL_DAYS),
            ],
        );

        if ($inserted > 0) {
            // We created the record — caller proceeds
            return new IdempotencyLock($id, $idempotencyKey, $actorId);
        }

        // Row already exists — SELECT FOR UPDATE SKIP LOCKED
        $existing = DB::connection('platform')
            ->table('platform.idempotency_records')
            ->where('actor_id', $actorId)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($existing === null) {
            // Row was deleted or we couldn't lock it — allow proceeding
            return new IdempotencyLock($id, $idempotencyKey, $actorId);
        }

        if ($existing->request_hash !== $requestHash || $existing->http_method !== $httpMethod) {
            Log::warning('idempotency.conflict', [
                'actor_id'        => $actorId,
                'idempotency_key' => $idempotencyKey,
            ]);
            throw new IdempotencyKeyMismatchException($correlationId);
        }

        if ($existing->status === 'processing') {
            throw new IdempotencyInProgressException($correlationId);
        }

        return new IdempotencyLock($existing->id, $idempotencyKey, $actorId);
    }

    public function recordResponse(IdempotencyLock $lock, int $statusCode, array $responseBody): void
    {
        $response = ['status' => $statusCode, 'body' => $responseBody];

        DB::connection('platform')
            ->table('platform.idempotency_records')
            ->where('actor_id', $lock->actorId)
            ->where('idempotency_key', $lock->idempotencyKey)
            ->update([
                'status'          => 'completed',
                'response_status' => $statusCode,
                'response_body'   => json_encode($responseBody),
                'updated_at'      => now(),
            ]);

        $this->cache->store(
            $lock->actorId,
            $lock->idempotencyKey,
            $response,
            self::RECORD_TTL_DAYS * 86400,
        );
    }

    public function recordFailure(IdempotencyLock $lock, \Throwable $e): void
    {
        DB::connection('platform')
            ->table('platform.idempotency_records')
            ->where('actor_id', $lock->actorId)
            ->where('idempotency_key', $lock->idempotencyKey)
            ->update(['status' => 'failed', 'updated_at' => now()]);
    }

    // Backward compat wrapper used by IdempotencyMiddleware
    public function resolve(
        string $idempotencyKey,
        string $actorId,
        string $httpMethod,
        string $httpPath,
        string $requestHash,
        string $correlationId,
    ): ?array {
        $completed = $this->findCompletedResponse($idempotencyKey, $actorId);
        if ($completed !== null) {
            return $completed;
        }

        $this->acquireLock($idempotencyKey, $actorId, $requestHash, $httpMethod, $httpPath, $correlationId);

        return null;
    }

    public function complete(string $idempotencyKey, string $actorId, int $responseStatus, array $responseBody): void
    {
        $lock = new IdempotencyLock('', $idempotencyKey, $actorId);
        $this->recordResponse($lock, $responseStatus, $responseBody);
    }
}
