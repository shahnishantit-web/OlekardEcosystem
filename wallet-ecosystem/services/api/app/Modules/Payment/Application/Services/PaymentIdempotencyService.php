<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Exceptions\IdempotencyInProgressException;
use App\Exceptions\IdempotencyKeyMismatchException;
use App\Modules\Payment\Domain\Contracts\PaymentIdempotencyRepositoryInterface;
use App\Modules\Payment\Domain\ValueObjects\IdempotencyResult;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Lock;

final class PaymentIdempotencyService
{
    /** @var array<string, Lock> Locks owned by this service instance, never another caller. */
    private array $heldLocks = [];
    public function __construct(
        private readonly PaymentIdempotencyRepositoryInterface $repository,
        private readonly ConfigurationServiceInterface         $config,
        private readonly CacheRepository                       $cache,
        private readonly ?LockProvider                         $lockProvider = null,
    ) {
    }

    /**
     * Check whether this request is a replay, a conflict, or a first-time call.
     *
     * Throws IdempotencyKeyMismatchException on hash conflict.
     * Throws IdempotencyInProgressException when the in-flight lock cannot be acquired.
     */
    public function check(
        string $actorId,
        string $scope,
        string $idempotencyKey,
        array  $requestBody,
        string $correlationId = '',
    ): IdempotencyResult {
        $hash     = $this->computeHash($requestBody);
        $redisKey = $this->redisKey($actorId, $scope, $idempotencyKey);

        // Fast path: Redis hit
        try {
            $cached = $this->cache->get($redisKey);
        } catch (\Throwable) {
            $cached = null; // Durable records remain authoritative during cache outage.
        }
        if ($cached !== null) {
            $payload = json_decode($cached, true);
            if (is_array($payload)) {
                if ($payload['hash'] !== $hash) {
                    throw new IdempotencyKeyMismatchException($correlationId);
                }
                return IdempotencyResult::replay((int) $payload['status'], (array) $payload['body']);
            }
        }

        // DB fallback
        $record = $this->repository->findRecord($actorId, $scope, $idempotencyKey);
        if ($record !== null) {
            if ($record->request_hash !== $hash) {
                throw new IdempotencyKeyMismatchException($correlationId);
            }

            // Repopulate Redis from DB
            $this->writeCache($redisKey, $hash, $record->response_status, $record->response_body);

            return IdempotencyResult::replay($record->response_status, $record->response_body);
        }

        // Acquire in-flight lock to prevent concurrent duplicate from re-executing
        $lockKey = 'payment:idem:lock:'.$redisKey;
        $provider = $this->lockProvider;
        if ($provider === null && $this->cache instanceof \Illuminate\Cache\Repository
            && $this->cache->getStore() instanceof LockProvider) {
            $provider = $this->cache->getStore();
        }
        if ($provider === null) {
            throw new IdempotencyInProgressException($correlationId);
        }
        $lock = $provider->lock($lockKey, 30);

        if (! $lock->get()) {
            // Lock held by concurrent request — wait briefly then re-check
            try {
                $acquired = $lock->block(30);
            } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
                $acquired = false;
            }
            if (! $acquired) {
                throw new IdempotencyInProgressException($correlationId);
            }
        }

        // Recheck even on immediate acquisition: the previous owner may have
        // committed between our first DB read and releasing its lock.
        $record = $this->repository->findRecord($actorId, $scope, $idempotencyKey);
        if ($record !== null) {
            $lock->release();
            if ($record->request_hash !== $hash) throw new IdempotencyKeyMismatchException($correlationId);
            $this->writeCache($redisKey, $hash, $record->response_status, $record->response_body);
            return IdempotencyResult::replay($record->response_status, $record->response_body);
        }
        $this->heldLocks[$redisKey] = $lock;

        return IdempotencyResult::proceed();
    }

    /**
     * Persist the idempotency record after successful operation execution.
     * Safe to call multiple times — uses insertOrIgnore.
     */
    public function store(
        string $actorId,
        string $scope,
        string $idempotencyKey,
        array  $requestBody,
        int    $responseStatus,
        array  $responseBody,
    ): void {
        $hash     = $this->computeHash($requestBody);
        $redisKey = $this->redisKey($actorId, $scope, $idempotencyKey);

        $this->repository->saveRecord($actorId, $scope, $idempotencyKey, $hash, $responseStatus, $responseBody);
        $this->writeCache($redisKey, $hash, $responseStatus, $responseBody);

        // Release in-flight lock if still held by this request
        if (isset($this->heldLocks[$redisKey])) {
            $this->heldLocks[$redisKey]->release();
            unset($this->heldLocks[$redisKey]);
        }
    }

    /**
     * Canonical hash: ksort + json_encode → sha256.
     * Ensures field-order-independent comparison.
     */
    private function computeHash(array $body): string
    {
        return hash('sha256', json_encode($this->canonicalize($body), JSON_THROW_ON_ERROR));
    }

    private function canonicalize(array $body): array
    {
        if (! array_is_list($body)) ksort($body);
        foreach ($body as $key => $value) {
            if (is_array($value)) {
                $body[$key] = $this->canonicalize($value);
            }
        }
        return $body;
    }

    private function redisKey(string $actorId, string $scope, string $idempotencyKey): string
    {
        return 'payment:idem:'.hash('sha256', "{$actorId}:{$scope}:{$idempotencyKey}");
    }

    private function writeCache(string $redisKey, string $hash, int $status, array $body): void
    {
        $ttlHours = (int) ($this->config->getOrDefault('payment.idempotency_redis_ttl_hours', 24) ?? 24);
        try {
            $this->cache->put(
                $redisKey,
                json_encode(['hash' => $hash, 'status' => $status, 'body' => $body]),
                now()->addHours($ttlHours),
            );
        } catch (\Throwable) {
            \Illuminate\Support\Facades\Log::warning('payment.idempotency.cache_write_unavailable');
        }
    }
}
