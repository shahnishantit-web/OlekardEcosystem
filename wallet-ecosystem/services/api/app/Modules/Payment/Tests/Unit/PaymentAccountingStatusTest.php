<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Domain\Enums\PaymentAccountingStatus;
use PHPUnit\Framework\TestCase;

final class PaymentAccountingStatusTest extends TestCase
{
    public function test_pending_ledger_is_not_terminal(): void
    {
        $this->assertFalse(PaymentAccountingStatus::PendingLedger->isTerminal());
    }

    public function test_ledger_posted_is_terminal(): void
    {
        $this->assertTrue(PaymentAccountingStatus::LedgerPosted->isTerminal());
    }

    public function test_ledger_failed_is_terminal(): void
    {
        $this->assertTrue(PaymentAccountingStatus::LedgerFailed->isTerminal());
    }

    public function test_pending_ledger_can_transition_to_ledger_posted(): void
    {
        $this->assertTrue(
            PaymentAccountingStatus::PendingLedger->canTransitionTo(PaymentAccountingStatus::LedgerPosted)
        );
    }

    public function test_pending_ledger_can_transition_to_ledger_failed(): void
    {
        $this->assertTrue(
            PaymentAccountingStatus::PendingLedger->canTransitionTo(PaymentAccountingStatus::LedgerFailed)
        );
    }

    public function test_ledger_posted_cannot_transition_back_to_pending_ledger(): void
    {
        $this->assertFalse(
            PaymentAccountingStatus::LedgerPosted->canTransitionTo(PaymentAccountingStatus::PendingLedger)
        );
    }

    public function test_ledger_failed_cannot_transition_back_to_pending_ledger(): void
    {
        $this->assertFalse(
            PaymentAccountingStatus::LedgerFailed->canTransitionTo(PaymentAccountingStatus::PendingLedger)
        );
    }
}
