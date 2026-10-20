<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Transfer;

use App\Modules\Transfer\Domain\Enums\TransferFailureReason;
use App\Modules\Transfer\Domain\Enums\TransferStatus;
use App\Modules\Transfer\Infrastructure\Models\TransferTransactionModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Ramsey\Uuid\Uuid;

/**
 * Feature tests for ExpirePendingTransfersCommand.
 *
 * Verifies:
 * - Pending transfers older than TTL are transitioned to failed(SYSTEM_ERROR)
 * - Recent pending transfers are NOT expired
 * - Already terminal transfers are NOT re-processed
 * - Artisan command returns success exit code
 */
final class ExpirePendingTransfersTest extends TransferTestCase
{
    public function test_old_pending_transfer_is_expired(): void
    {
        $tx = $this->createPendingTransfer(createdMinutesAgo: 20);

        Artisan::call('transfer:expire-pending');

        $tx->refresh();

        $this->assertSame(TransferStatus::Failed->value, $tx->status->value);
        $this->assertSame(TransferFailureReason::SystemError->value, $tx->failure_reason->value);
    }

    public function test_recent_pending_transfer_is_not_expired(): void
    {
        $tx = $this->createPendingTransfer(createdMinutesAgo: 2);

        Artisan::call('transfer:expire-pending');

        $tx->refresh();

        $this->assertSame(TransferStatus::Pending->value, $tx->status->value);
    }

    public function test_completed_transfer_is_not_touched(): void
    {
        $tx = $this->createPendingTransfer(createdMinutesAgo: 20);
        $tx->status = TransferStatus::Completed;
        $tx->save();

        Artisan::call('transfer:expire-pending');

        $tx->refresh();

        $this->assertSame(TransferStatus::Completed->value, $tx->status->value);
    }

    public function test_command_returns_success_exit_code(): void
    {
        $exitCode = Artisan::call('transfer:expire-pending');
        $this->assertSame(0, $exitCode);
    }

    public function test_expired_transfer_has_audit_record(): void
    {
        $this->createPendingTransfer(createdMinutesAgo: 20);

        Artisan::call('transfer:expire-pending');

        $this->assertAuditEventRecorded('transfer.transaction.expired');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createPendingTransfer(int $createdMinutesAgo): TransferTransactionModel
    {
        $tx = new TransferTransactionModel();
        $tx->id                          = (string) Uuid::uuid7();
        $tx->transfer_reference          = 'TXF-TEST12345';
        $tx->sender_customer_id          = $this->senderCustomerId;
        $tx->sender_wallet_id            = $this->senderWalletId;
        $tx->sender_pocket_ledger_ref    = $this->senderPocketRef;
        $tx->recipient_customer_id       = $this->recipientCustomerId;
        $tx->recipient_wallet_id         = $this->recipientWalletId;
        $tx->recipient_pocket_ledger_ref = $this->recipientPocketRef;
        $tx->currency_code               = $this->currencyCode;
        $tx->gross_amount                = 10000;
        $tx->fee_amount                  = 0;
        $tx->net_amount                  = 10000;
        $tx->fee_rate_bps                = 0;
        $tx->status                      = TransferStatus::Pending;
        $tx->failure_reason              = null;
        $tx->idempotency_key             = (string) Uuid::uuid7();
        $tx->correlation_id              = (string) Uuid::uuid7();
        $tx->save();

        // Backdate the record
        $tx->created_at = Carbon::now()->subMinutes($createdMinutesAgo);
        $tx->save();

        return $tx->fresh();
    }
}
