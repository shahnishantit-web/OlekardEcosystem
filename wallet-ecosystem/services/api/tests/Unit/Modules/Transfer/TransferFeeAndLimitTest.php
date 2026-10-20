<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Transfer;

use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Transfer\Application\Services\TransferFeeService;
use App\Modules\Transfer\Application\Services\TransferLimitService;
use App\Modules\Transfer\Domain\Exceptions\DailyLimitExceededException;
use App\Modules\Transfer\Domain\Exceptions\InsufficientBalanceException;
use Mockery;
use Tests\TestCase;

/**
 * Unit tests for TransferFeeService and TransferLimitService.
 * These run without a database; all external deps are mocked.
 */
final class TransferFeeAndLimitTest extends TestCase
{
    // ------------------------------------------------------------------
    // TransferFeeService
    // ------------------------------------------------------------------

    /** @test */
    public function fee_is_zero_when_rate_bps_is_zero(): void
    {
        $config = Mockery::mock(ConfigurationServiceInterface::class);
        $service = new TransferFeeService($config);

        $result = $service->calculate(100000, 0);

        $this->assertSame(0, $result['fee_amount']);
        $this->assertSame(100000, $result['net_amount']);
        $this->assertSame(0, $result['fee_rate_bps']);
    }

    /** @test */
    public function fee_rounds_half_up_using_standard_rounding(): void
    {
        $config = Mockery::mock(ConfigurationServiceInterface::class);
        $service = new TransferFeeService($config);

        // 100 * 25 / 10000 = 0.25 → rounds to 0
        $result = $service->calculate(100, 25);
        $this->assertSame(0, $result['fee_amount']);

        // 200 * 25 / 10000 = 0.5 → rounds to 1 (PHP_ROUND_HALF_UP behaviour of intval(round()))
        $result2 = $service->calculate(200, 25);
        $this->assertSame(1, $result2['fee_amount']);
    }

    /** @test */
    public function fee_formula_is_gross_times_rate_divided_by_10000(): void
    {
        $config = Mockery::mock(ConfigurationServiceInterface::class);
        $service = new TransferFeeService($config);

        // 500000 * 150 / 10000 = 7500
        $result = $service->calculate(500000, 150);
        $this->assertSame(7500, $result['fee_amount']);
        $this->assertSame(492500, $result['net_amount']);
        $this->assertSame(150, $result['fee_rate_bps']);
    }

    /** @test */
    public function net_amount_equals_gross_minus_fee(): void
    {
        $config = Mockery::mock(ConfigurationServiceInterface::class);
        $service = new TransferFeeService($config);

        $gross  = 123456;
        $rateBps = 200;
        $result  = $service->calculate($gross, $rateBps);

        $this->assertSame($gross - $result['fee_amount'], $result['net_amount']);
    }

    /** @test */
    public function get_current_rate_bps_reads_from_config(): void
    {
        $config = Mockery::mock(ConfigurationServiceInterface::class);
        $config->shouldReceive('getOrDefault')
            ->with('transfer.fee.rate_bps', 0)
            ->once()
            ->andReturn(150);

        $service = new TransferFeeService($config);
        $this->assertSame(150, $service->getCurrentRateBps('BWP'));
    }

    /** @test */
    public function fee_rate_changed_returns_true_when_rate_changed(): void
    {
        $config = Mockery::mock(ConfigurationServiceInterface::class);
        $config->shouldReceive('getOrDefault')
            ->with('transfer.fee.rate_bps', 0)
            ->andReturn(200);

        $service = new TransferFeeService($config);
        $this->assertTrue($service->hasFeeRateChanged(150, 'BWP'));
    }

    /** @test */
    public function fee_rate_changed_returns_false_when_rate_unchanged(): void
    {
        $config = Mockery::mock(ConfigurationServiceInterface::class);
        $config->shouldReceive('getOrDefault')
            ->with('transfer.fee.rate_bps', 0)
            ->andReturn(150);

        $service = new TransferFeeService($config);
        $this->assertFalse($service->hasFeeRateChanged(150, 'BWP'));
    }

    // ------------------------------------------------------------------
    // TransferLimitService
    // ------------------------------------------------------------------

    /** @test */
    public function validate_min_throws_on_amount_below_minimum(): void
    {
        $config = Mockery::mock(ConfigurationServiceInterface::class);
        $config->shouldReceive('getOrDefault')
            ->with('transfer.min_amount', 100)
            ->andReturn(100);

        $service = new TransferLimitService($config);

        $this->expectException(\InvalidArgumentException::class);
        $service->validateMin(50, 'BWP');
    }

    /** @test */
    public function validate_min_passes_on_exact_minimum(): void
    {
        $config = Mockery::mock(ConfigurationServiceInterface::class);
        $config->shouldReceive('getOrDefault')
            ->with('transfer.min_amount', 100)
            ->andReturn(100);

        $service = new TransferLimitService($config);
        $service->validateMin(100, 'BWP'); // must not throw
        $this->addToAssertionCount(1);
    }

    /** @test */
    public function validate_max_throws_on_amount_above_maximum(): void
    {
        $config = Mockery::mock(ConfigurationServiceInterface::class);
        $config->shouldReceive('getOrDefault')
            ->with('transfer.max_amount', 500000)
            ->andReturn(500000);

        $service = new TransferLimitService($config);

        $this->expectException(\InvalidArgumentException::class);
        $service->validateMax(500001, 'BWP');
    }

    /** @test */
    public function validate_max_passes_on_exact_maximum(): void
    {
        $config = Mockery::mock(ConfigurationServiceInterface::class);
        $config->shouldReceive('getOrDefault')
            ->with('transfer.max_amount', 500000)
            ->andReturn(500000);

        $service = new TransferLimitService($config);
        $service->validateMax(500000, 'BWP'); // must not throw
        $this->addToAssertionCount(1);
    }

    /** @test */
    public function validate_balance_throws_insufficient_balance_exception(): void
    {
        $config  = Mockery::mock(ConfigurationServiceInterface::class);
        $service = new TransferLimitService($config);

        $this->expectException(InsufficientBalanceException::class);
        $service->validateBalance(1000, 500);
    }

    /** @test */
    public function validate_balance_passes_when_balance_equals_gross(): void
    {
        $config  = Mockery::mock(ConfigurationServiceInterface::class);
        $service = new TransferLimitService($config);

        $service->validateBalance(1000, 1000); // must not throw
        $this->addToAssertionCount(1);
    }

    /** @test */
    public function validate_balance_passes_when_balance_exceeds_gross(): void
    {
        $config  = Mockery::mock(ConfigurationServiceInterface::class);
        $service = new TransferLimitService($config);

        $service->validateBalance(1000, 5000); // must not throw
        $this->addToAssertionCount(1);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
