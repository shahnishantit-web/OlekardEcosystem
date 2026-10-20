<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Withdrawal;

use App\Modules\Withdrawal\Application\Contracts\WithdrawalRiskResult;
use App\Modules\Withdrawal\Infrastructure\Stubs\WithdrawalRiskServiceStub;
use PHPUnit\Framework\TestCase;

final class WithdrawalRiskServiceTest extends TestCase
{
    /** @test */
    public function stub_always_returns_approved(): void
    {
        $stub   = new WithdrawalRiskServiceStub();
        $result = $stub->evaluate([
            'customer_id'   => 'cust-1',
            'wallet_id'     => 'wallet-1',
            'channel'       => 'mobile_money',
            'gross_amount'  => 10000,
            'currency_code' => 'KES',
        ]);

        $this->assertTrue($result->isApproved());
        $this->assertNull($result->getReason());
    }

    /** @test */
    public function risk_result_approved_factory(): void
    {
        $result = WithdrawalRiskResult::approved();

        $this->assertTrue($result->isApproved());
        $this->assertNull($result->getReason());
    }

    /** @test */
    public function risk_result_rejected_factory(): void
    {
        $result = WithdrawalRiskResult::rejected('velocity_exceeded');

        $this->assertFalse($result->isApproved());
        $this->assertSame('velocity_exceeded', $result->getReason());
    }
}
