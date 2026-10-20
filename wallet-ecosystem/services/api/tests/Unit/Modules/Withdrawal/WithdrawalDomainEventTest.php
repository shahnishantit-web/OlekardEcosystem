<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Withdrawal;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;
use App\Modules\Withdrawal\Domain\Events\WithdrawalAmountMismatch;
use App\Modules\Withdrawal\Domain\Events\WithdrawalCancelled;
use App\Modules\Withdrawal\Domain\Events\WithdrawalCompleted;
use App\Modules\Withdrawal\Domain\Events\WithdrawalExpired;
use App\Modules\Withdrawal\Domain\Events\WithdrawalFailed;
use App\Modules\Withdrawal\Domain\Events\WithdrawalInitiated;
use App\Modules\Withdrawal\Domain\Events\WithdrawalLateCallback;
use App\Modules\Withdrawal\Domain\Events\WithdrawalProcessing;
use App\Modules\Withdrawal\Domain\Events\WithdrawalRiskRejected;
use PHPUnit\Framework\TestCase;

final class WithdrawalDomainEventTest extends TestCase
{
    /** @test */
    public function withdrawal_initiated_event_name_is_correct(): void
    {
        $event = new WithdrawalInitiated(
            withdrawalTransactionId: 'tx-1',
            walletId:                'wallet-1',
            currencyCode:            'KES',
            grossAmount:             10000,
            channel:                 'mobile_money',
            transactionReference:    'WD-20260918-001',
            occurredAt:              '2026-09-18T10:00:00Z',
            correlationId:           'corr-1',
        );

        $this->assertSame('Withdrawal.Transaction.Initiated', $event->eventName());
        $this->assertSame('corr-1', $event->correlationId());
    }

    /** @test */
    public function withdrawal_initiated_does_not_include_payout_destination(): void
    {
        $event = new WithdrawalInitiated(
            withdrawalTransactionId: 'tx-1',
            walletId:                'wallet-1',
            currencyCode:            'KES',
            grossAmount:             10000,
            channel:                 'mobile_money',
            transactionReference:    'WD-20260918-001',
            occurredAt:              '2026-09-18T10:00:00Z',
            correlationId:           'corr-1',
        );

        $payload = $event->toArray();
        $this->assertArrayNotHasKey('payout_destination', $payload);
    }

    /** @test */
    public function withdrawal_completed_event_carries_journal_id(): void
    {
        $event = new WithdrawalCompleted(
            withdrawalTransactionId: 'tx-1',
            walletId:                'wallet-1',
            currencyCode:            'KES',
            grossAmount:             10000,
            feeAmount:               100,
            netPayoutAmount:         9900,
            channel:                 'mobile_money',
            ledgerJournalId:         'journal-abc',
            occurredAt:              '2026-09-18T10:00:00Z',
            correlationId:           'corr-1',
        );

        $this->assertSame('Withdrawal.Transaction.Completed', $event->eventName());
        $this->assertSame('journal-abc', $event->toArray()['ledger_journal_id']);
    }

    /** @test */
    public function withdrawal_failed_event_carries_failure_reason(): void
    {
        $event = new WithdrawalFailed(
            withdrawalTransactionId: 'tx-1',
            walletId:                'wallet-1',
            currencyCode:            'KES',
            grossAmount:             10000,
            channel:                 'mobile_money',
            failureReason:           'provider_declined',
            occurredAt:              '2026-09-18T10:00:00Z',
            correlationId:           'corr-1',
        );

        $this->assertSame('Withdrawal.Transaction.Failed', $event->eventName());
        $this->assertSame('provider_declined', $event->toArray()['failure_reason']);
    }

    /** @test */
    public function withdrawal_amount_mismatch_event_carries_both_amounts(): void
    {
        $event = new WithdrawalAmountMismatch(
            withdrawalTransactionId: 'tx-1',
            walletId:                'wallet-1',
            currencyCode:            'KES',
            grossAmount:             10000,
            channel:                 'mobile_money',
            expectedAmount:          9900,
            callbackAmount:          9500,
            occurredAt:              '2026-09-18T10:00:00Z',
            correlationId:           'corr-1',
        );

        $this->assertSame('Withdrawal.Transaction.AmountMismatch', $event->eventName());
        $payload = $event->toArray();
        $this->assertSame(9900, $payload['expected_amount']);
        $this->assertSame(9500, $payload['callback_amount']);
    }

    /** @test */
    public function late_callback_event_carries_provider_reference(): void
    {
        $event = new WithdrawalLateCallback(
            withdrawalTransactionId: 'tx-1',
            walletId:                'wallet-1',
            currencyCode:            'KES',
            grossAmount:             10000,
            channel:                 'mobile_money',
            providerReferenceId:     'mock-payout-tx-1',
            occurredAt:              '2026-09-18T10:00:00Z',
            correlationId:           'corr-1',
        );

        $this->assertSame('Withdrawal.Transaction.LateCallback', $event->eventName());
        $this->assertSame('mock-payout-tx-1', $event->toArray()['provider_reference_id']);
    }

    /** @test */
    public function all_events_implement_integration_event_interface(): void
    {
        $this->assertInstanceOf(IntegrationEventInterface::class,
            new WithdrawalInitiated('t', 'w', 'KES', 1, 'mm', 'ref', '2026-01-01T00:00:00Z', 'c'));

        $this->assertInstanceOf(IntegrationEventInterface::class,
            new WithdrawalCompleted('t', 'w', 'KES', 1, 0, 1, 'mm', 'j', '2026-01-01T00:00:00Z', 'c'));

        $this->assertInstanceOf(IntegrationEventInterface::class,
            new WithdrawalFailed('t', 'w', 'KES', 1, 'mm', 'reason', '2026-01-01T00:00:00Z', 'c'));

        $this->assertInstanceOf(IntegrationEventInterface::class,
            new WithdrawalCancelled('t', 'w', 'KES', 1, 'mm', '2026-01-01T00:00:00Z', 'c'));

        $this->assertInstanceOf(IntegrationEventInterface::class,
            new WithdrawalExpired('t', 'w', 'KES', 1, 'mm', '2026-01-01T00:00:00Z', 'c'));

        $this->assertInstanceOf(IntegrationEventInterface::class,
            new WithdrawalLateCallback('t', 'w', 'KES', 1, 'mm', 'ref', '2026-01-01T00:00:00Z', 'c'));

        $this->assertInstanceOf(IntegrationEventInterface::class,
            new WithdrawalProcessing('t', 'w', 'KES', 1, 'mm', 'hold-1', '2026-01-01T00:00:00Z', 'c'));

        $this->assertInstanceOf(IntegrationEventInterface::class,
            new WithdrawalRiskRejected('t', 'w', 'KES', 1, 'mm', 'risk_reason', '2026-01-01T00:00:00Z', 'c'));

        $this->assertInstanceOf(IntegrationEventInterface::class,
            new WithdrawalAmountMismatch('t', 'w', 'KES', 1, 'mm', 9900, 9500, '2026-01-01T00:00:00Z', 'c'));

        $this->assertTrue(true);
    }
}
