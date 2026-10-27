<?php

declare(strict_types=1);

use App\Exceptions\IdempotencyInProgressException;
use App\Exceptions\IdempotencyKeyMismatchException;
use App\Modules\Platform\Application\Services\IdempotencyService;
use App\Modules\Platform\Infrastructure\Cache\IdempotencyCacheRepository;
use App\Modules\Platform\Infrastructure\Persistence\IdempotencyKeyModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\UnitTestCase;

uses(UnitTestCase::class);

beforeEach(function () {
    Log::spy();
    $this->cache   = mock(IdempotencyCacheRepository::class);
    $this->service = new IdempotencyService($this->cache);
});

test('completed response lookup can use the cache without a DB query', function () {
    $this->cache->shouldReceive('find')
        ->once()
        ->andReturn(['status' => 200, 'body' => ['success' => true]]);

    $result = $this->service->findCompletedResponse('key-1', 'actor-1');

    expect($result)->toEqual(['status' => 200, 'body' => ['success' => true]]);
});

test('cache miss creates processing record and returns null on first call', function () {
    $this->cache->shouldReceive('find')->once()->andReturn(null);

    DB::shouldReceive('connection')->andReturnSelf();

    $result = $this->service->resolve('key-2', 'actor-2', 'POST', '/v1/test', 'hash-xyz', 'corr-2');

    expect($result)->toBeNull();
})->skip('Requires full DB chain mock (table->where->first + affectingStatement) — use feature test');

test('concurrent duplicate throws IdempotencyInProgressException', function () {
    $this->cache->shouldReceive('find')->andReturn(null);

    // Simulate existing record with status=processing
    $model = new IdempotencyKeyModel([
        'status'       => 'processing',
        'request_hash' => 'same-hash',
        'http_method'  => 'POST',
    ]);

    DB::partialMock()->shouldReceive('table')->andReturnSelf();

    expect(fn () => $this->service->resolve('key-3', 'actor-3', 'POST', '/v1/test', 'same-hash', 'corr-3'))
        ->toThrow(IdempotencyInProgressException::class);
})->skip('Requires real DB for full flow');

test('hash mismatch throws IdempotencyKeyMismatchException', function () {
    $this->cache->shouldReceive('find')->andReturn(null);

    expect(fn () => $this->service->resolve('key-4', 'actor-4', 'POST', '/v1/test', 'different-hash', 'corr-4'))
        ->toThrow(IdempotencyKeyMismatchException::class);
})->skip('Requires real DB for full flow');

test('system calls use zero UUID for actor_id', function () {
    $zeroUuid = '00000000-0000-0000-0000-000000000000';

    $this->cache->shouldReceive('find')
        ->with($zeroUuid, 'system-key')
        ->andReturn(null);

    // Verify zero UUID is accepted (no exception thrown)
    expect(fn () => $this->service->resolve('system-key', $zeroUuid, 'POST', '/v1/test', 'hash', 'corr'))
        ->not->toThrow(\InvalidArgumentException::class);
});
