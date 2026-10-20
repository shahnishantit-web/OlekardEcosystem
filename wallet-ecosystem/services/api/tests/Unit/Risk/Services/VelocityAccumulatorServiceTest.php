<?php

declare(strict_types=1);

namespace Tests\Unit\Risk\Services;

use App\Modules\Risk\Application\Services\VelocityAccumulatorService;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

final class VelocityAccumulatorServiceTest extends TestCase
{
    private VelocityAccumulatorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new VelocityAccumulatorService();
    }

    public function test_buildKey_format(): void
    {
        $key = $this->service->buildKey('cust-123', 'transfer', 86400);

        $this->assertSame('velocity:cust-123:transfer:86400', $key);
    }

    public function test_record_adds_member_and_sets_ttl(): void
    {
        $mockConn = Mockery::mock();
        $mockConn->shouldReceive('zadd')
            ->once()
            ->withArgs(function (string $key, float $score, string $member): bool {
                return str_starts_with($key, 'velocity:')
                    && str_ends_with($member, ':50.00');
            })
            ->andReturn(1);
        $mockConn->shouldReceive('expire')
            ->once()
            ->with(Mockery::type('string'), 86700) // 86400 + 300 buffer
            ->andReturn(1);

        Redis::shouldReceive('connection')->once()->andReturn($mockConn);

        $key = $this->service->buildKey('cust-abc', 'transfer', 86400);
        $this->service->record($key, '50.00', 86400);
    }

    public function test_getCount_returns_count_within_window(): void
    {
        $mockConn = Mockery::mock();
        $mockConn->shouldReceive('zcount')
            ->once()
            ->with(Mockery::type('string'), Mockery::type('float'), '+inf')
            ->andReturn(3);

        Redis::shouldReceive('connection')->once()->andReturn($mockConn);

        $key   = $this->service->buildKey('cust-abc', 'transfer', 86400);
        $count = $this->service->getCount($key, 86400);

        $this->assertSame(3, $count);
    }

    public function test_getCount_returns_zero_when_no_entries(): void
    {
        $mockConn = Mockery::mock();
        $mockConn->shouldReceive('zcount')
            ->once()
            ->andReturn(0);

        Redis::shouldReceive('connection')->once()->andReturn($mockConn);

        $key   = $this->service->buildKey('cust-abc', 'transfer', 86400);
        $count = $this->service->getCount($key, 86400);

        $this->assertSame(0, $count);
    }

    public function test_getAmount_sums_amounts_in_window(): void
    {
        // Member format: "{timestamp}-{uniqid}:{amount}"
        $members = [
            microtime(true).'-abc123:100.00',
            microtime(true).'-def456:250.50',
            microtime(true).'-ghi789:75.25',
        ];

        $mockConn = Mockery::mock();
        $mockConn->shouldReceive('zrangebyscore')
            ->once()
            ->andReturn($members);

        Redis::shouldReceive('connection')->once()->andReturn($mockConn);

        $key    = $this->service->buildKey('cust-abc', 'transfer', 86400);
        $amount = $this->service->getAmount($key, 86400);

        $this->assertSame('425.7500', $amount);
    }

    public function test_getAmount_returns_zero_for_empty_window(): void
    {
        $mockConn = Mockery::mock();
        $mockConn->shouldReceive('zrangebyscore')
            ->once()
            ->andReturn([]);

        Redis::shouldReceive('connection')->once()->andReturn($mockConn);

        $key    = $this->service->buildKey('cust-abc', 'transfer', 86400);
        $amount = $this->service->getAmount($key, 86400);

        $this->assertSame('0', $amount);
    }

    public function test_record_ttl_includes_buffer(): void
    {
        $windowSeconds = 3600;
        $expectedTtl   = $windowSeconds + 300;

        $mockConn = Mockery::mock();
        $mockConn->shouldReceive('zadd')->andReturn(1);
        $mockConn->shouldReceive('expire')
            ->once()
            ->with(Mockery::type('string'), $expectedTtl)
            ->andReturn(1);

        Redis::shouldReceive('connection')->once()->andReturn($mockConn);

        $key = $this->service->buildKey('cust-abc', 'transfer', $windowSeconds);
        $this->service->record($key, '100.00', $windowSeconds);
    }
}
