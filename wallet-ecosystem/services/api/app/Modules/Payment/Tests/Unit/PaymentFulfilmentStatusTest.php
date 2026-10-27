<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Domain\Enums\PaymentFulfilmentStatus;
use PHPUnit\Framework\TestCase;

final class PaymentFulfilmentStatusTest extends TestCase
{
    public function test_pending_is_not_terminal(): void
    {
        $this->assertFalse(PaymentFulfilmentStatus::Pending->isTerminal());
    }

    public function test_fulfilled_is_terminal(): void
    {
        $this->assertTrue(PaymentFulfilmentStatus::Fulfilled->isTerminal());
    }

    public function test_fulfilment_failed_is_terminal(): void
    {
        $this->assertTrue(PaymentFulfilmentStatus::FulfilmentFailed->isTerminal());
    }

    public function test_pending_can_transition_to_fulfilled(): void
    {
        $this->assertTrue(
            PaymentFulfilmentStatus::Pending->canTransitionTo(PaymentFulfilmentStatus::Fulfilled)
        );
    }

    public function test_pending_can_transition_to_fulfilment_failed(): void
    {
        $this->assertTrue(
            PaymentFulfilmentStatus::Pending->canTransitionTo(PaymentFulfilmentStatus::FulfilmentFailed)
        );
    }

    public function test_fulfilled_cannot_transition_back_to_pending(): void
    {
        $this->assertFalse(
            PaymentFulfilmentStatus::Fulfilled->canTransitionTo(PaymentFulfilmentStatus::Pending)
        );
    }

    public function test_fulfilment_failed_cannot_transition_back_to_pending(): void
    {
        $this->assertFalse(
            PaymentFulfilmentStatus::FulfilmentFailed->canTransitionTo(PaymentFulfilmentStatus::Pending)
        );
    }
}
