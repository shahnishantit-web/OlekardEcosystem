<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Domain\Enums\ConfigChangeDraftStatus;
use PHPUnit\Framework\TestCase;

final class ConfigChangeDraftStatusTest extends TestCase
{
    public function test_pending_approval_is_not_terminal(): void
    {
        $this->assertFalse(ConfigChangeDraftStatus::PendingApproval->isTerminal());
    }

    public function test_approved_is_terminal(): void
    {
        $this->assertTrue(ConfigChangeDraftStatus::Approved->isTerminal());
    }

    public function test_rejected_is_terminal(): void
    {
        $this->assertTrue(ConfigChangeDraftStatus::Rejected->isTerminal());
    }

    public function test_withdrawn_is_terminal(): void
    {
        $this->assertTrue(ConfigChangeDraftStatus::Withdrawn->isTerminal());
    }

    public function test_pending_approval_can_transition_to_approved(): void
    {
        $this->assertTrue(
            ConfigChangeDraftStatus::PendingApproval->canTransitionTo(ConfigChangeDraftStatus::Approved)
        );
    }

    public function test_pending_approval_can_transition_to_rejected(): void
    {
        $this->assertTrue(
            ConfigChangeDraftStatus::PendingApproval->canTransitionTo(ConfigChangeDraftStatus::Rejected)
        );
    }

    public function test_pending_approval_can_transition_to_withdrawn(): void
    {
        $this->assertTrue(
            ConfigChangeDraftStatus::PendingApproval->canTransitionTo(ConfigChangeDraftStatus::Withdrawn)
        );
    }

    public function test_approved_cannot_transition_to_any_state(): void
    {
        foreach (ConfigChangeDraftStatus::cases() as $target) {
            $this->assertFalse(
                ConfigChangeDraftStatus::Approved->canTransitionTo($target),
                "Approved should not transition to {$target->value}"
            );
        }
    }

    public function test_rejected_cannot_transition_to_any_state(): void
    {
        foreach (ConfigChangeDraftStatus::cases() as $target) {
            $this->assertFalse(
                ConfigChangeDraftStatus::Rejected->canTransitionTo($target),
                "Rejected should not transition to {$target->value}"
            );
        }
    }

    public function test_withdrawn_cannot_transition_to_any_state(): void
    {
        foreach (ConfigChangeDraftStatus::cases() as $target) {
            $this->assertFalse(
                ConfigChangeDraftStatus::Withdrawn->canTransitionTo($target),
                "Withdrawn should not transition to {$target->value}"
            );
        }
    }
}
