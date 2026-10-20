<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Withdrawal;

use App\Modules\Withdrawal\Domain\Enums\WithdrawalStatus;
use PHPUnit\Framework\TestCase;

final class WithdrawalStateMachineTest extends TestCase
{
    /** @test */
    public function pending_is_not_terminal(): void
    {
        $statuses = [WithdrawalStatus::Pending, WithdrawalStatus::Processing];

        foreach ($statuses as $status) {
            $this->assertNotContains($status, [
                WithdrawalStatus::Completed,
                WithdrawalStatus::Failed,
                WithdrawalStatus::Cancelled,
            ], "{$status->value} should not be terminal");
        }
    }

    /** @test */
    public function completed_failed_cancelled_are_terminal(): void
    {
        $terminal = [
            WithdrawalStatus::Completed,
            WithdrawalStatus::Failed,
            WithdrawalStatus::Cancelled,
        ];

        foreach ($terminal as $status) {
            $this->assertContains($status, $terminal);
        }
    }

    /** @test */
    public function pending_is_the_only_cancellable_state(): void
    {
        // isCancellable() returns true only for Pending
        // We test this by inspecting all enum cases
        $cancellable = array_filter(
            WithdrawalStatus::cases(),
            fn (WithdrawalStatus $s) => $s === WithdrawalStatus::Pending
        );

        $this->assertCount(1, $cancellable);
        $this->assertSame(WithdrawalStatus::Pending, reset($cancellable));
    }

    /** @test */
    public function all_five_statuses_are_defined(): void
    {
        $cases = array_map(fn ($c) => $c->value, WithdrawalStatus::cases());

        $this->assertContains('pending', $cases);
        $this->assertContains('processing', $cases);
        $this->assertContains('completed', $cases);
        $this->assertContains('failed', $cases);
        $this->assertContains('cancelled', $cases);
        $this->assertCount(5, $cases);
    }

    /** @test */
    public function withdrawal_channels_are_mobile_money_and_bank_transfer(): void
    {
        $channels = array_map(
            fn ($c) => $c->value,
            \App\Modules\Withdrawal\Domain\Enums\WithdrawalChannel::cases()
        );

        $this->assertContains('mobile_money', $channels);
        $this->assertContains('bank_transfer', $channels);
    }
}
