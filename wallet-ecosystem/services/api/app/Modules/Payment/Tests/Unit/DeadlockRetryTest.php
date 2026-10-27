<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Domain\Exceptions\ConcurrentModificationException;
use App\Modules\Payment\Domain\Traits\DeadlockRetryTrait;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

final class DeadlockRetryTest extends TestCase
{
    use DeadlockRetryTrait;

    /** @var ConfigurationServiceInterface&MockObject */
    private ConfigurationServiceInterface $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = $this->createMock(ConfigurationServiceInterface::class);
        $this->config->method('getOrDefault')->willReturnCallback(
            static fn (string $key, mixed $default) => $default
        );
    }

    private function makeDeadlockException(): QueryException
    {
        $pdoException       = new \PDOException('deadlock detected');
        $pdoException->errorInfo = ['40P01', null, 'deadlock detected'];

        return $this->queryException('40P01');
    }

    private function queryException(string $state): QueryException
    {
        $previous = new class($state) extends \PDOException {
            public function __construct(string $state) {
                parent::__construct('Simulated PostgreSQL error');
                $this->code = $state;
                $this->errorInfo = [$state, null, 'Simulated PostgreSQL error'];
            }
        };
        return new QueryException('payment', 'SELECT 1', [], $previous);
    }

    /** Test 1: Callable succeeds on first try — no retries */
    public function test_succeeds_on_first_try_without_retries(): void
    {
        $calls = 0;
        $result = $this->withDeadlockRetry(function () use (&$calls): string {
            $calls++;
            return 'ok';
        }, 'test_resource');

        $this->assertSame('ok', $result);
        $this->assertSame(1, $calls);
    }

    /** Test 2: Callable deadlocks once, succeeds on second try */
    public function test_retries_once_and_succeeds(): void
    {
        $calls    = 0;
        $deadlock = $this->makeDeadlockException();

        $result = $this->withDeadlockRetry(function () use (&$calls, $deadlock): string {
            $calls++;
            if ($calls === 1) {
                throw $deadlock;
            }
            return 'recovered';
        }, 'test_resource');

        $this->assertSame('recovered', $result);
        $this->assertSame(2, $calls);
    }

    /** Test 3: Callable deadlocks max_retries (3) times → throws ConcurrentModificationException */
    public function test_throws_concurrent_modification_after_max_retries(): void
    {
        $deadlock = $this->makeDeadlockException();

        $this->expectException(ConcurrentModificationException::class);

        $this->withDeadlockRetry(function () use ($deadlock): void {
            throw $deadlock;
        }, 'payment_intent:test-123');
    }

    /** Test 4: Non-deadlock QueryException (e.g., 23505 unique violation) is NOT retried */
    public function test_non_deadlock_query_exception_is_not_retried(): void
    {
        $calls          = 0;
        $uniqueViolation = $this->queryException('23505');

        $this->expectException(QueryException::class);

        $this->withDeadlockRetry(function () use (&$calls, $uniqueViolation): void {
            $calls++;
            throw $uniqueViolation;
        }, 'test_resource');

        $this->assertSame(1, $calls);
    }
}
