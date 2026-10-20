<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Transaction;

use App\Modules\Transaction\Domain\Enums\ReversalStatus;
use PHPUnit\Framework\TestCase;

final class ReversalStatusEnumTest extends TestCase
{
    /** @test */
    public function all_five_statuses_are_defined(): void
    {
        $values = array_map(fn (ReversalStatus $s) => $s->value, ReversalStatus::cases());

        $this->assertContains('pending_approval', $values);
        $this->assertContains('approved', $values);
        $this->assertContains('rejected', $values);
        $this->assertContains('executed', $values);
        $this->assertContains('failed', $values);
        $this->assertCount(5, $values);
    }

    /** @test */
    public function rejected_executed_failed_are_terminal(): void
    {
        $this->assertTrue(ReversalStatus::Rejected->isTerminal());
        $this->assertTrue(ReversalStatus::Executed->isTerminal());
        $this->assertTrue(ReversalStatus::Failed->isTerminal());
    }

    /** @test */
    public function pending_approval_and_approved_are_not_terminal(): void
    {
        $this->assertFalse(ReversalStatus::PendingApproval->isTerminal());
        $this->assertFalse(ReversalStatus::Approved->isTerminal());
    }

    /** @test */
    public function pending_approval_and_approved_are_active(): void
    {
        $this->assertTrue(ReversalStatus::PendingApproval->isActive());
        $this->assertTrue(ReversalStatus::Approved->isActive());
    }

    /** @test */
    public function terminal_statuses_are_not_active(): void
    {
        $this->assertFalse(ReversalStatus::Rejected->isActive());
        $this->assertFalse(ReversalStatus::Executed->isActive());
        $this->assertFalse(ReversalStatus::Failed->isActive());
    }

    /** @test */
    public function active_and_terminal_are_mutually_exclusive(): void
    {
        foreach (ReversalStatus::cases() as $status) {
            $this->assertNotSame(
                $status->isActive(),
                $status->isTerminal(),
                "Status [{$status->value}] should not be both active and terminal."
            );
        }
    }

    /** @test */
    public function lifecycle_order_is_pending_approved_executed(): void
    {
        $active   = array_filter(ReversalStatus::cases(), fn (ReversalStatus $s) => $s->isActive());
        $terminal = array_filter(ReversalStatus::cases(), fn (ReversalStatus $s) => $s->isTerminal());

        $this->assertCount(2, $active);
        $this->assertCount(3, $terminal);
    }
}
