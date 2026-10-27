# Contract: PaymentIdempotencyService

**Interface**: `App\Modules\Payment\Domain\Contracts\PaymentIdempotencyRepositoryInterface`
**Service**: `App\Modules\Payment\Application\Services\PaymentIdempotencyService`
**Date**: 2026-10-21

---

## Interface Definition

```php
interface PaymentIdempotencyRepositoryInterface
{
    /**
     * Find an existing idempotency record for the given actor, scope, and key.
     * Returns null if not found.
     */
    public function findRecord(
        string $actorId,
        string $scope,
        string $idempotencyKey,
    ): ?PaymentIdempotencyRecordModel;

    /**
     * Persist a new idempotency record with the given response data.
     */
    public function saveRecord(
        string $actorId,
        string $scope,
        string $idempotencyKey,
        string $requestHash,
        int    $responseStatus,
        array  $responseBody,
    ): PaymentIdempotencyRecordModel;

    /**
     * Delete expired records (where expires_at < NOW()).
     * Used by the pruning artisan command.
     */
    public function pruneExpired(): int;
}
```

---

## PaymentIdempotencyService Public API

### `check(string $actorId, string $scope, string $idempotencyKey, array $requestBody): IdempotencyResult`

Called by controllers before executing the operation.

**Returns** an `IdempotencyResult` with one of three outcomes:

| `isReplay` | `isConflict` | Meaning | Controller Action |
|------------|--------------|---------|-------------------|
| `true` | `false` | Cached response found, hash matches | Return `$result->responseStatus` + `$result->responseBody` immediately |
| `false` | `true` | Key reused with different body hash | Return `HTTP 422` + `IDEMPOTENCY_KEY_MISMATCH` |
| `false` | `false` | First time this key is seen | Execute the operation, then call `store()` |

**Side effects**: On cache hit from DB (Redis miss), repopulates Redis.

### `store(string $actorId, string $scope, string $idempotencyKey, array $requestBody, int $responseStatus, array $responseBody): void`

Called by controllers after successful operation execution. Writes to both DB and Redis.

**Idempotent**: Calling `store()` twice for the same key is safe (uses `insertOrIgnore` in DB).

---

## In-Flight Lock Protocol

When `check()` returns `isReplay=false, isConflict=false`, the service acquires a Redis lock:

```
Lock key: payment:idem:lock:{sha256(actorId:scope:idempotencyKey)}
TTL: 30 seconds
Acquire: Cache::lock(key, 30)->block(30)
```

The lock is held during the operation execution and released after `store()`.

Controllers do not need to manage the lock directly — `PaymentIdempotencyService` handles it.

If `block(30)` times out (lock held > 30s), a `LockTimeoutException` is caught and the service retries the `check()` — the operation may have completed and the record should now exist.

---

## Error Codes (OpenAPI `ErrorCode` enum additions)

| Code | HTTP Status | When |
|------|------------|------|
| `IDEMPOTENCY_KEY_MISMATCH` | 422 | Key reused with different request body |
| `CONCURRENT_MODIFICATION` | 409 | DB deadlock retries exhausted |

---

## Configuration Keys (Platform Configuration Service)

| Key | Default | Description |
|-----|---------|-------------|
| `payment.idempotency_ttl_days` | `90` | Days to retain idempotency records in DB |
| `payment.idempotency_redis_ttl_hours` | `24` | Hours to cache idempotency responses in Redis |
| `payment.crash_recovery_threshold_seconds` | `60` | Seconds before SUBMITTING attempt triggers crash recovery |
| `payment.deadlock_max_retries` | `3` | Max DB transaction deadlock retries |
| `payment.deadlock_retry_base_ms` | `10` | Base delay in ms for deadlock retry backoff |
