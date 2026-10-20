<?php

declare(strict_types=1);

use App\Modules\Platform\Application\Services\IdempotencyService;
use App\Modules\Platform\Infrastructure\Cache\IdempotencyCacheRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Tests\Support\ConcurrencyTestHelper;

uses(\Tests\Support\PlatformTestCase::class);

beforeEach(function () {
    DB::connection('platform')->table('platform.idempotency_records')->truncate();
});

test('completed idempotency record is returned from PostgreSQL on cache miss', function () {
    $actorId       = (string) Uuid::uuid7();
    $key           = (string) Uuid::uuid7();
    $correlationId = (string) Uuid::uuid7();

    /** @var IdempotencyService $service */
    $service = app(IdempotencyService::class);

    // Insert a completed record directly
    DB::connection('platform')->table('platform.idempotency_records')->insert([
        'id'              => (string) Uuid::uuid7(),
        'idempotency_key' => $key,
        'actor_id'        => $actorId,
        'actor_type'      => 'USER',
        'request_hash'    => 'abc123',
        'http_method'     => 'POST',
        'http_path'       => '/v1/test',
        'status'          => 'completed',
        'attempt_count'   => 1,
        'response_status' => 201,
        'response_body'   => json_encode(['id' => 'resource-1']),
        'created_at'      => now(),
        'expires_at'      => now()->addDay(),
    ]);

    $result = $service->findCompletedResponse($key, $actorId);

    expect($result)->not->toBeNull();
    expect($result['status'])->toBe(201);
    expect($result['body'])->toBe(['id' => 'resource-1']);
});

test('acquireLock inserts new record and returns lock', function () {
    $actorId       = (string) Uuid::uuid7();
    $key           = (string) Uuid::uuid7();
    $correlationId = (string) Uuid::uuid7();

    /** @var IdempotencyService $service */
    $service = app(IdempotencyService::class);

    DB::connection('platform')->beginTransaction();

    $lock = $service->acquireLock($key, $actorId, 'hash123', 'POST', '/v1/test', $correlationId);

    DB::connection('platform')->commit();

    expect($lock)->not->toBeNull();
    expect($lock->idempotencyKey)->toBe($key);
    expect($lock->actorId)->toBe($actorId);

    $row = DB::connection('platform')
        ->table('platform.idempotency_records')
        ->where('actor_id', $actorId)
        ->where('idempotency_key', $key)
        ->first();

    expect($row)->not->toBeNull();
    expect($row->status)->toBe('processing');
});

test('replay: second request after completion returns stored response', function () {
    $actorId       = (string) Uuid::uuid7();
    $key           = (string) Uuid::uuid7();
    $correlationId = (string) Uuid::uuid7();

    /** @var IdempotencyService $service */
    $service = app(IdempotencyService::class);

    // First call: acquire lock, record response
    DB::connection('platform')->beginTransaction();
    $lock = $service->acquireLock($key, $actorId, 'hash-a', 'POST', '/v1/wallets', $correlationId);
    DB::connection('platform')->commit();

    $service->recordResponse($lock, 201, ['wallet_id' => 'w-abc']);

    // Second call: should return the stored response
    $result = $service->findCompletedResponse($key, $actorId);

    expect($result)->not->toBeNull();
    expect($result['status'])->toBe(201);
    expect($result['body']['wallet_id'])->toBe('w-abc');
});

test('different actor with same key proceeds independently', function () {
    $key           = (string) Uuid::uuid7();
    $correlationId = (string) Uuid::uuid7();
    $actor1        = (string) Uuid::uuid7();
    $actor2        = (string) Uuid::uuid7();

    /** @var IdempotencyService $service */
    $service = app(IdempotencyService::class);

    DB::connection('platform')->beginTransaction();
    $lock1 = $service->acquireLock($key, $actor1, 'hash-x', 'POST', '/v1/test', $correlationId);
    DB::connection('platform')->commit();

    DB::connection('platform')->beginTransaction();
    $lock2 = $service->acquireLock($key, $actor2, 'hash-x', 'POST', '/v1/test', $correlationId);
    DB::connection('platform')->commit();

    expect($lock1->actorId)->toBe($actor1);
    expect($lock2->actorId)->toBe($actor2);

    $count = DB::connection('platform')
        ->table('platform.idempotency_records')
        ->where('idempotency_key', $key)
        ->count();

    expect($count)->toBe(2);
});

test('system call uses zero UUID actor_id', function () {
    $key           = (string) Uuid::uuid7();
    $correlationId = (string) Uuid::uuid7();

    /** @var IdempotencyService $service */
    $service = app(IdempotencyService::class);

    DB::connection('platform')->beginTransaction();
    // Pass empty actorId — service uses zero UUID for system calls
    $lock = $service->acquireLock($key, '', 'hash-sys', 'POST', '/v1/internal', $correlationId);
    DB::connection('platform')->commit();

    $row = DB::connection('platform')
        ->table('platform.idempotency_records')
        ->where('idempotency_key', $key)
        ->first();

    expect($row->actor_id)->toBe('00000000-0000-0000-0000-000000000000');
});

test('Redis cache is warmed after successful response recording', function () {
    $actorId       = (string) Uuid::uuid7();
    $key           = (string) Uuid::uuid7();
    $correlationId = (string) Uuid::uuid7();

    /** @var IdempotencyService $service */
    $service = app(IdempotencyService::class);

    DB::connection('platform')->beginTransaction();
    $lock = $service->acquireLock($key, $actorId, 'hash-r', 'POST', '/v1/test', $correlationId);
    DB::connection('platform')->commit();

    $service->recordResponse($lock, 200, ['ok' => true]);

    // Cache should now contain the response
    $cache = app(IdempotencyCacheRepository::class);
    $cached = $cache->find($actorId, $key);

    expect($cached)->not->toBeNull();
    expect($cached['status'])->toBe(200);
});

test('concurrent requests with same actor+key: one proceeds, other throws IdempotencyInProgressException', function () {
    $actorId       = (string) Uuid::uuid7();
    $key           = (string) Uuid::uuid7();
    $correlationId = (string) Uuid::uuid7();

    /** @var IdempotencyService $service */
    $service = app(IdempotencyService::class);

    // Insert a "processing" record to simulate another request holding the lock
    DB::connection('platform')->table('platform.idempotency_records')->insert([
        'id'              => (string) Uuid::uuid7(),
        'idempotency_key' => $key,
        'actor_id'        => $actorId,
        'actor_type'      => 'USER',
        'request_hash'    => 'hash-c',
        'http_method'     => 'POST',
        'http_path'       => '/v1/test',
        'status'          => 'processing',
        'attempt_count'   => 1,
        'created_at'      => now(),
        'expires_at'      => now()->addDay(),
    ]);

    expect(fn () => $service->acquireLock($key, $actorId, 'hash-c', 'POST', '/v1/test', $correlationId))
        ->toThrow(\App\Exceptions\IdempotencyInProgressException::class);
});

test('findCompletedResponse logs idempotency.replay with source=cache on Redis hit', function () {
    $actorId       = (string) Uuid::uuid7();
    $key           = (string) Uuid::uuid7();
    $correlationId = (string) Uuid::uuid7();

    /** @var IdempotencyService $service */
    $service = app(IdempotencyService::class);

    // Warm the cache via a full acquire+record cycle
    DB::connection('platform')->beginTransaction();
    $lock = $service->acquireLock($key, $actorId, 'hash-log-cache', 'POST', '/v1/test', $correlationId);
    DB::connection('platform')->commit();
    $service->recordResponse($lock, 200, ['source_test' => 'cache']);

    Log::spy();

    $result = $service->findCompletedResponse($key, $actorId);

    expect($result)->not->toBeNull();
    Log::shouldHaveReceived('info')
        ->once()
        ->with('idempotency.replay', \Mockery::subset([
            'actor_id'        => $actorId,
            'idempotency_key' => $key,
            'source'          => 'cache',
        ]));
});

test('findCompletedResponse logs idempotency.replay with source=database on cache miss', function () {
    $actorId = (string) Uuid::uuid7();
    $key     = (string) Uuid::uuid7();

    // Insert directly into DB — cache is never warmed for this unique key
    DB::connection('platform')->table('platform.idempotency_records')->insert([
        'id'              => (string) Uuid::uuid7(),
        'idempotency_key' => $key,
        'actor_id'        => $actorId,
        'actor_type'      => 'USER',
        'request_hash'    => 'hash-log-db',
        'http_method'     => 'POST',
        'http_path'       => '/v1/test',
        'status'          => 'completed',
        'attempt_count'   => 1,
        'response_status' => 200,
        'response_body'   => json_encode(['source_test' => 'database']),
        'created_at'      => now(),
        'updated_at'      => now(),
        'expires_at'      => now()->addDay(),
    ]);

    /** @var IdempotencyService $service */
    $service = app(IdempotencyService::class);

    Log::spy();

    $result = $service->findCompletedResponse($key, $actorId);

    expect($result)->not->toBeNull();
    Log::shouldHaveReceived('info')
        ->once()
        ->with('idempotency.replay', \Mockery::subset([
            'actor_id'        => $actorId,
            'idempotency_key' => $key,
            'source'          => 'database',
        ]));
});
