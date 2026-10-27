<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Exceptions\IdempotencyInProgressException;
use App\Exceptions\IdempotencyKeyMismatchException;
use App\Modules\Payment\Application\Services\PaymentIdempotencyService;
use App\Modules\Payment\Domain\Contracts\PaymentIdempotencyRepositoryInterface;
use App\Modules\Payment\Domain\ValueObjects\IdempotencyResult;
use App\Modules\Payment\Infrastructure\Models\PaymentIdempotencyRecordModel;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

final class IdempotencyServiceTest extends TestCase
{
    private PaymentIdempotencyRepositoryInterface&MockObject $repository;
    private ConfigurationServiceInterface&MockObject         $config;
    private CacheRepository&MockObject                       $cache;
    private \Illuminate\Contracts\Cache\LockProvider&MockObject $locks;
    private PaymentIdempotencyService                        $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->createMock(PaymentIdempotencyRepositoryInterface::class);
        $this->config     = $this->createMock(ConfigurationServiceInterface::class);
        $this->cache      = $this->createMock(CacheRepository::class);
        $this->locks = $this->createMock(\Illuminate\Contracts\Cache\LockProvider::class);

        $this->config->method('getOrDefault')->willReturnCallback(
            static fn (string $key, mixed $default) => $default
        );

        $this->service = new PaymentIdempotencyService(
            $this->repository,
            $this->config,
            $this->cache,
            $this->locks,
        );
    }

    /** Test 1: Redis hit — returns replay without hitting DB */
    public function test_cache_outage_replays_durable_response_without_acquiring_lock(): void
    {
        $record = new PaymentIdempotencyRecordModel();
        $record->request_hash = hash('sha256', json_encode(['amount' => 1000]));
        $record->response_status = 201;
        $record->response_body = ['id' => 'committed'];
        $this->cache->method('get')->willThrowException(new \RuntimeException('unavailable'));
        $this->cache->method('put')->willThrowException(new \RuntimeException('unavailable'));
        $this->repository->method('findRecord')->willReturn($record);
        $this->locks->expects($this->never())->method('lock');
        $result = $this->service->check('actor', 'create', 'key', ['amount' => 1000]);
        $this->assertTrue($result->isReplay);
        $this->assertSame(['id' => 'committed'], $result->responseBody);
    }

    public function test_commit_between_initial_read_and_lock_acquisition_is_replayed(): void
    {
        $record = new PaymentIdempotencyRecordModel();
        $record->request_hash = hash('sha256', json_encode(['amount' => 1000]));
        $record->response_status = 201;
        $record->response_body = ['id' => 'winner'];
        $this->repository->expects($this->exactly(2))->method('findRecord')->willReturnOnConsecutiveCalls(null, $record);
        $lock = $this->createMock(\Illuminate\Contracts\Cache\Lock::class);
        $lock->method('get')->willReturn(true);
        $lock->expects($this->once())->method('release');
        $lock->expects($this->never())->method('forceRelease');
        $this->locks->method('lock')->willReturn($lock);
        $this->assertTrue($this->service->check('actor', 'create', 'key', ['amount' => 1000])->isReplay);
    }

    public function test_stale_owner_cannot_release_new_owners_lock(): void
    {
        $store = new \Illuminate\Cache\ArrayStore();
        $cache = new \Illuminate\Cache\Repository($store);
        $service = new PaymentIdempotencyService($this->repository, $this->config, $cache, $store);
        $service->check('actor', 'create', 'key', ['amount' => 1000]);
        $lockKey = 'payment:idem:lock:payment:idem:'.hash('sha256', 'actor:create:key');
        // Simulate lease expiry before a second owner acquires the same key.
        $store->lock($lockKey)->forceRelease();
        $newOwner = $store->lock($lockKey, 30);
        $this->assertTrue($newOwner->get());
        $service->store('actor', 'create', 'key', ['amount' => 1000], 201, ['id' => 'committed']);
        $this->assertFalse($store->lock($lockKey, 30)->get());
        $this->assertTrue($newOwner->release());
    }

    public function test_hash_sorts_object_keys_but_preserves_string_and_list_values(): void
    {
        $hashes = [];
        $this->repository->method('saveRecord')->willReturnCallback(
            static function ($actor, $scope, $key, $hash) use (&$hashes): void { $hashes[] = $hash; }
        );
        foreach ([['b' => 2, 'a' => ' x '], ['a' => ' x ', 'b' => 2], ['a' => 'x', 'b' => 2], ['list' => [2, 1]], ['list' => [1, 2]]] as $index => $body) {
            $this->service->store('actor', 'create', (string) $index, $body, 201, []);
        }
        $this->assertSame($hashes[0], $hashes[1]);
        $this->assertNotSame($hashes[1], $hashes[2]);
        $this->assertNotSame($hashes[3], $hashes[4]);
    }

    public function test_redis_hit_returns_replay_without_db(): void
    {
        $hash    = hash('sha256', json_encode(['amount' => 1000]));
        $payload = json_encode(['hash' => $hash, 'status' => 201, 'body' => ['data' => ['id' => 'intent-1']]]);

        $this->cache->method('get')->willReturn($payload);
        $this->repository->expects($this->never())->method('findRecord');

        $result = $this->service->check('actor-1', 'payment.intent.create', 'key-A', ['amount' => 1000]);

        $this->assertTrue($result->isReplay);
        $this->assertFalse($result->isConflict);
        $this->assertSame(201, $result->responseStatus);
        $this->assertSame(['data' => ['id' => 'intent-1']], $result->responseBody);
    }

    /** Test 2: Redis miss, DB hit — returns replay and repopulates Redis */
    public function test_db_fallback_returns_replay_and_repopulates_redis(): void
    {
        $hash   = hash('sha256', json_encode(['amount' => 1000]));
        $record = new PaymentIdempotencyRecordModel();
        $record->request_hash    = $hash;
        $record->response_status = 201;
        $record->response_body   = ['data' => ['id' => 'intent-2']];

        $this->cache->method('get')->willReturn(null);
        $this->cache->expects($this->once())->method('put');
        $this->repository->method('findRecord')->willReturn($record);

        $result = $this->service->check('actor-1', 'payment.intent.create', 'key-A', ['amount' => 1000]);

        $this->assertTrue($result->isReplay);
        $this->assertSame(201, $result->responseStatus);
    }

    /** Test 3: Key reuse with different body hash — throws IdempotencyKeyMismatchException */
    public function test_hash_mismatch_throws_key_mismatch_exception(): void
    {
        $existingHash = hash('sha256', json_encode(['amount' => 1000]));
        $payload      = json_encode(['hash' => $existingHash, 'status' => 201, 'body' => []]);

        $this->cache->method('get')->willReturn($payload);

        $this->expectException(IdempotencyKeyMismatchException::class);

        // Different body — different hash
        $this->service->check('actor-1', 'payment.intent.create', 'key-A', ['amount' => 2000]);
    }

    /** Test 4: First-time key — returns proceed */
    public function test_first_time_key_returns_proceed(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->repository->method('findRecord')->willReturn(null);

        $lockMock = $this->createMock(\Illuminate\Contracts\Cache\Lock::class);
        $lockMock->method('get')->willReturn(true);
        $this->locks->method('lock')->willReturn($lockMock);

        $result = $this->service->check('actor-1', 'payment.intent.create', 'key-new', ['amount' => 500]);

        $this->assertFalse($result->isReplay);
        $this->assertFalse($result->isConflict);
    }

    /** Test 5: store() writes to both Redis and DB */
    public function test_store_writes_to_redis_and_db(): void
    {
        $this->repository->expects($this->once())->method('saveRecord');
        $this->cache->expects($this->once())->method('put');

        $lockMock = $this->createMock(\Illuminate\Contracts\Cache\Lock::class);
        $lockMock->method('get')->willReturn(true);
        $lockMock->expects($this->once())->method('release');
        $lockMock->expects($this->never())->method('forceRelease');
        $this->locks->method('lock')->willReturn($lockMock);
        $this->service->check('actor-1', 'payment.intent.create', 'key-A', ['amount' => 1000]);

        $this->service->store(
            'actor-1',
            'payment.intent.create',
            'key-A',
            ['amount' => 1000],
            201,
            ['data' => ['id' => 'intent-3']],
        );
    }
}
