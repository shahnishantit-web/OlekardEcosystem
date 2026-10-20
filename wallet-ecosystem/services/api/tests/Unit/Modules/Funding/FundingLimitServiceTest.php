<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Funding;

use App\Modules\Funding\Application\Services\FundingLimitService;
use App\Modules\Funding\Domain\Enums\FundingMethod;
use App\Modules\Funding\Domain\Exceptions\FundingLimitExceededException;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use PHPUnit\Framework\TestCase;

final class FundingLimitServiceTest extends TestCase
{
    private function makeService(
        int $min       = 100,
        int $maxTx     = 500_000,
        int $dailyMax  = 2_000_000,
        int $dailySpent = 0,
    ): FundingLimitService {
        $config = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('get')->willReturnCallback(fn (string $k) => match ($k) {
            'funding.limits.min_amount'          => (string) $min,
            'funding.limits.max_per_transaction' => (string) $maxTx,
            'funding.limits.daily_max'           => (string) $dailyMax,
            default                              => null,
        });

        // Partial mock: override dailyTotalForCustomer via anonymous class
        return new class($config, $dailySpent) extends FundingLimitService {
            public function __construct(
                ConfigurationServiceInterface $config,
                private int $stubDailySpent,
            ) {
                parent::__construct($config);
            }

            public function dailyTotalForCustomer(string $customerId, string $currencyCode): int
            {
                return $this->stubDailySpent;
            }
        };
    }

    public function test_passes_within_limits(): void
    {
        $this->makeService()->assertWithinLimits('c1', 10_000, FundingMethod::MobileMoney, 'BWP');
        $this->assertTrue(true); // no exception thrown
    }

    public function test_throws_below_minimum(): void
    {
        $this->expectException(FundingLimitExceededException::class);
        $this->makeService()->assertWithinLimits('c1', 50, FundingMethod::MobileMoney, 'BWP');
    }

    public function test_throws_above_per_transaction_max(): void
    {
        $this->expectException(FundingLimitExceededException::class);
        $this->makeService()->assertWithinLimits('c1', 600_000, FundingMethod::MobileMoney, 'BWP');
    }

    public function test_throws_when_daily_limit_would_be_exceeded(): void
    {
        $this->expectException(FundingLimitExceededException::class);
        $this->makeService(dailySpent: 1_900_000)
            ->assertWithinLimits('c1', 200_000, FundingMethod::MobileMoney, 'BWP');
    }

    public function test_agent_method_skips_daily_limit_check(): void
    {
        // Even with daily spent near limit, agent method should pass
        $this->makeService(dailySpent: 1_900_000)
            ->assertWithinLimits('c1', 200_000, FundingMethod::Agent, 'BWP');

        $this->assertTrue(true);
    }

    public function test_limit_type_field_set_correctly_for_minimum(): void
    {
        try {
            $this->makeService()->assertWithinLimits('c1', 50, FundingMethod::MobileMoney, 'BWP');
            $this->fail('Expected FundingLimitExceededException');
        } catch (FundingLimitExceededException $e) {
            $this->assertSame('minimum', $e->limitType);
            $this->assertSame('FUNDING_BELOW_MINIMUM', $e->errorCode);
        }
    }

    public function test_limit_type_field_set_correctly_for_daily(): void
    {
        try {
            $this->makeService(dailySpent: 1_900_000)
                ->assertWithinLimits('c1', 200_000, FundingMethod::MobileMoney, 'BWP');
            $this->fail('Expected FundingLimitExceededException');
        } catch (FundingLimitExceededException $e) {
            $this->assertSame('daily_max', $e->limitType);
            $this->assertSame('FUNDING_DAILY_LIMIT_EXCEEDED', $e->errorCode);
        }
    }
}
