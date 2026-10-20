<?php

declare(strict_types=1);

use App\Exceptions\ModuleConnectionUnavailableException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class);

test('ModuleConnectionUnavailableException exposes accessors correctly', function () {
    $correlationId = 'test-correlation-id';
    $ex = new ModuleConnectionUnavailableException('identity', 'identity', $correlationId);

    expect($ex->getModuleName())->toBe('identity');
    expect($ex->getConnectionName())->toBe('identity');
    expect($ex->getCorrelationId())->toBe($correlationId);
    expect($ex)->toBeInstanceOf(\RuntimeException::class);
});

test('Handler maps ModuleConnectionUnavailableException directly to HTTP 503 SERVICE_UNAVAILABLE', function () {
    $correlationId = (string) \Ramsey\Uuid\Uuid::uuid7();

    Route::get('/test-connection-unavailable-direct', function () use ($correlationId) {
        throw new ModuleConnectionUnavailableException('wallet', 'wallet', $correlationId);
    });

    $this->withHeaders(['X-Correlation-ID' => $correlationId])
        ->getJson('/test-connection-unavailable-direct')
        ->assertStatus(503)
        ->assertJson(['success' => false, 'data' => null])
        ->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');
});

test('Handler detects PDO connection failure in QueryException and returns HTTP 503', function () {
    $correlationId = (string) \Ramsey\Uuid\Uuid::uuid7();

    Route::get('/test-pdo-connection-fail', function () {
        throw new QueryException(
            'wallet',
            'SELECT 1',
            [],
            new \PDOException('SQLSTATE[HY000] [2002] Connection refused'),
        );
    });

    $this->withHeaders(['X-Correlation-ID' => $correlationId])
        ->getJson('/test-pdo-connection-fail')
        ->assertStatus(503)
        ->assertJson(['success' => false])
        ->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');
});

test('Handler returns 503 with Retry-After header for connection failures', function () {
    $correlationId = (string) \Ramsey\Uuid\Uuid::uuid7();

    Route::get('/test-retry-after', function () use ($correlationId) {
        throw new ModuleConnectionUnavailableException('ledger', 'ledger', $correlationId);
    });

    $response = $this->withHeaders(['X-Correlation-ID' => $correlationId])
        ->getJson('/test-retry-after')
        ->assertStatus(503);

    expect($response->headers->get('Retry-After'))->toBe('60');
});

test('Handler logs ERROR when ModuleConnectionUnavailableException is caught', function () {
    $correlationId = (string) \Ramsey\Uuid\Uuid::uuid7();

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context) use ($correlationId) {
            return $message === 'Module connection unavailable'
                && $context['module_name'] === 'identity'
                && $context['connection_name'] === 'identity'
                && $context['correlation_id'] === $correlationId;
        });

    Route::get('/test-log-error', function () use ($correlationId) {
        throw new ModuleConnectionUnavailableException('identity', 'identity', $correlationId);
    });

    $this->withHeaders(['X-Correlation-ID' => $correlationId])
        ->getJson('/test-log-error')
        ->assertStatus(503);
});

test('ordinary SQL syntax QueryException returns HTTP 500 INTERNAL_ERROR not 503', function () {
    $correlationId = (string) \Ramsey\Uuid\Uuid::uuid7();

    Route::get('/test-sql-syntax-error', function () {
        throw new QueryException(
            'platform',
            'SELECT bad syntax HERE',
            [],
            new \PDOException('You have an error in your SQL syntax'),
        );
    });

    $this->withHeaders(['X-Correlation-ID' => $correlationId])
        ->getJson('/test-sql-syntax-error')
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'INTERNAL_ERROR');
});

test('QueryException with constraint violation returns HTTP 500 not 503', function () {
    $correlationId = (string) \Ramsey\Uuid\Uuid::uuid7();

    Route::get('/test-constraint-error', function () {
        throw new QueryException(
            'platform',
            'INSERT INTO ...',
            [],
            new \PDOException('SQLSTATE[23000]: Integrity constraint violation: duplicate key'),
        );
    });

    $this->withHeaders(['X-Correlation-ID' => $correlationId])
        ->getJson('/test-constraint-error')
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'INTERNAL_ERROR');
});
