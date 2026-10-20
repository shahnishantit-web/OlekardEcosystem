<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Funding;

use App\Modules\Funding\Application\Services\FundingFeeService;
use App\Modules\Funding\Domain\Enums\FundingMethod;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use PHPUnit\Framework\TestCase;

final class FundingFeeServiceTest extends TestCase
{
    private function makeService(float $mobileMoneyRate = 0.015, float $bankRate = 0.01): FundingFeeService
    {
        $config = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('get')->willReturnCallback(function (string $key) use ($mobileMoneyRate, $bankRate): ?string {
            return match ($key) {
                'funding.fee.mobile_money.rate' => (string) $mobileMoneyRate,
                'funding.fee.bank_transfer.rate' => (string) $bankRate,
                default                         => null,
            };
        });

        return new FundingFeeService($config);
    }

    public function test_agent_method_always_zero_fee(): void
    {
        $service = $this->makeService();
        $result  = $service->calculate(100_00, FundingMethod::Agent);

        $this->assertSame(0, $result['fee_amount']);
        $this->assertSame(100_00, $result['net_amount']);
    }

    public function test_mobile_money_fee_calculated_correctly(): void
    {
        $service = $this->makeService(mobileMoneyRate: 0.015); // 1.5%
        $result  = $service->calculate(10_000, FundingMethod::MobileMoney); // 100.00 BWP

        // fee = round(10000 * 0.015) = round(150) = 150
        $this->assertSame(150, $result['fee_amount']);
        $this->assertSame(9_850, $result['net_amount']);
    }

    public function test_zero_rate_yields_zero_fee(): void
    {
        $service = $this->makeService(mobileMoneyRate: 0.0);
        $result  = $service->calculate(10_000, FundingMethod::MobileMoney);

        $this->assertSame(0, $result['fee_amount']);
        $this->assertSame(10_000, $result['net_amount']);
    }

    public function test_has_fee_changed_returns_true_when_fee_differs(): void
    {
        $service = $this->makeService(mobileMoneyRate: 0.015);

        // Quoted fee was 0 (mismatch with actual 150)
        $this->assertTrue($service->hasFeeChanged(10_000, FundingMethod::MobileMoney, 0));
    }

    public function test_has_fee_changed_returns_false_when_fee_matches(): void
    {
        $service = $this->makeService(mobileMoneyRate: 0.015);

        // Actual fee is 150; quoted is 150
        $this->assertFalse($service->hasFeeChanged(10_000, FundingMethod::MobileMoney, 150));
    }

    public function test_fee_rounding_rounds_half_up(): void
    {
        $service = $this->makeService(mobileMoneyRate: 0.015);
        // 999 * 0.015 = 14.985 → round → 15
        $result = $service->calculate(999, FundingMethod::MobileMoney);
        $this->assertSame(15, $result['fee_amount']);
        $this->assertSame(984, $result['net_amount']);
    }
}
