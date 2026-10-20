<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Withdrawal;

use App\Modules\Withdrawal\Domain\Exceptions\FeeMismatchException;
use App\Modules\Withdrawal\Domain\Exceptions\WithdrawalLimitViolationException;
use App\Modules\Withdrawal\Domain\Exceptions\WithdrawalNotFoundException;
use App\Modules\Withdrawal\Domain\Exceptions\WithdrawalNotCancellableException;
use App\Modules\Withdrawal\Domain\Exceptions\WithdrawalRiskRejectedException;
use App\Modules\Withdrawal\Domain\Exceptions\InvalidPinException;
use PHPUnit\Framework\TestCase;

final class WithdrawalExceptionTest extends TestCase
{
    /** @test */
    public function fee_mismatch_exception_carries_updated_amounts(): void
    {
        $e = new FeeMismatchException(150, 9850);

        $this->assertSame(150, $e->updatedFeeAmount);
        $this->assertSame(9850, $e->updatedNetPayoutAmount);
    }

    /** @test */
    public function limit_violation_below_minimum_factory(): void
    {
        $e = WithdrawalLimitViolationException::belowMinimum(100, 500);

        $this->assertSame('AMOUNT_BELOW_MINIMUM', $e->violationCode);
        $this->assertArrayHasKey('minimum', $e->details);
        $this->assertSame(500, $e->details['minimum']);
    }

    /** @test */
    public function limit_violation_above_maximum_factory(): void
    {
        $e = WithdrawalLimitViolationException::aboveMaximum(100000, 50000);

        $this->assertSame('AMOUNT_ABOVE_MAXIMUM', $e->violationCode);
        $this->assertSame(50000, $e->details['maximum']);
    }

    /** @test */
    public function limit_violation_daily_limit_exceeded_factory(): void
    {
        $e = WithdrawalLimitViolationException::dailyLimitExceeded(5000, 10000, 8000);

        $this->assertSame('DAILY_LIMIT_EXCEEDED', $e->violationCode);
        $this->assertSame(10000, $e->details['daily_max']);
        $this->assertSame(8000, $e->details['daily_used']);
    }

    /** @test */
    public function withdrawal_not_found_exception_message(): void
    {
        $e = new WithdrawalNotFoundException('tx-123');

        $this->assertStringContainsString('tx-123', $e->getMessage());
    }

    /** @test */
    public function withdrawal_not_cancellable_exception_message(): void
    {
        $e = new WithdrawalNotCancellableException('processing');

        $this->assertStringContainsString('processing', $e->getMessage());
    }

    /** @test */
    public function risk_rejected_exception_carries_reason(): void
    {
        $e = new WithdrawalRiskRejectedException('velocity_exceeded');

        $this->assertStringContainsString('velocity_exceeded', $e->getMessage());
    }

    /** @test */
    public function invalid_pin_exception_is_runtime_exception(): void
    {
        $e = new InvalidPinException();

        $this->assertInstanceOf(\RuntimeException::class, $e);
    }
}
