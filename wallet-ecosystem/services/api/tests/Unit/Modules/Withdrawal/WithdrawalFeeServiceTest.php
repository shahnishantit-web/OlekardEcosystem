<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Withdrawal;

use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Withdrawal\Application\Services\WithdrawalFeeService;
use App\Modules\Withdrawal\Domain\Enums\WithdrawalChannel;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class WithdrawalFeeServiceTest extends TestCase
{
    private ConfigurationServiceInterface&MockObject $configMock;
    private WithdrawalFeeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configMock = $this->createMock(ConfigurationServiceInterface::class);
        $this->service    = new WithdrawalFeeService($this->configMock);
    }

    /** @test */
    public function calculates_zero_fee_when_rate_bps_is_zero(): void
    {
        $this->configMock->method('get')->willReturn(0);

        $result = $this->service->calculate(10000, WithdrawalChannel::MobileMoney, 'KES');

        $this->assertSame(0, $result['fee_amount']);
        $this->assertSame(10000, $result['net_payout_amount']);
        $this->assertSame(0, $result['fee_rate_bps']);
    }

    /** @test */
    public function calculates_correct_fee_for_150_bps(): void
    {
        $this->configMock->method('get')->willReturn(150);

        $result = $this->service->calculate(10000, WithdrawalChannel::MobileMoney, 'KES');

        // 10000 * 150 / 10000 = 150
        $this->assertSame(150, $result['fee_amount']);
        $this->assertSame(9850, $result['net_payout_amount']);
        $this->assertSame(150, $result['fee_rate_bps']);
    }

    /** @test */
    public function fee_is_rounded_for_fractional_amounts(): void
    {
        $this->configMock->method('get')->willReturn(100);

        // 10001 * 100 / 10000 = 100.01 → round → 100
        $result = $this->service->calculate(10001, WithdrawalChannel::MobileMoney, 'KES');

        $this->assertSame(100, $result['fee_amount']);
        $this->assertSame(9901, $result['net_payout_amount']);
    }

    /** @test */
    public function returns_zero_fee_when_no_config_exists(): void
    {
        $this->configMock->method('get')->willReturn(null);

        $result = $this->service->calculate(50000, WithdrawalChannel::BankTransfer, 'USD');

        $this->assertSame(0, $result['fee_amount']);
        $this->assertSame(50000, $result['net_payout_amount']);
    }

    /** @test */
    public function gross_amount_equals_fee_plus_net(): void
    {
        $this->configMock->method('get')->willReturn(200);

        $result = $this->service->calculate(7500, WithdrawalChannel::MobileMoney, 'KES');

        $this->assertSame(7500, $result['fee_amount'] + $result['net_payout_amount']);
    }

    /** @test */
    public function queries_correct_config_key(): void
    {
        $this->configMock->expects($this->once())
            ->method('get')
            ->with('withdrawal.fee.mobile_money.KES')
            ->willReturn(100);

        $this->service->calculate(10000, WithdrawalChannel::MobileMoney, 'KES');
    }
}
