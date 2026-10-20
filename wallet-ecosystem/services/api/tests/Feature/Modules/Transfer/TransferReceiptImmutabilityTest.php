<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Transfer;

use App\Modules\Transfer\Domain\Exceptions\TransferReceiptImmutableException;
use App\Modules\Transfer\Domain\Enums\TransferStatus;
use App\Modules\Transfer\Infrastructure\Models\TransferReceiptModel;
use App\Modules\Transfer\Infrastructure\Models\TransferTransactionModel;
use Ramsey\Uuid\Uuid;

/**
 * Feature tests for T060: TransferReceiptModel immutability.
 *
 * Asserts:
 * - Initial insert succeeds
 * - save() on existing model throws TransferReceiptImmutableException
 * - delete() throws TransferReceiptImmutableException
 * - update() throws TransferReceiptImmutableException
 */
final class TransferReceiptImmutabilityTest extends TransferTestCase
{
    public function test_initial_save_succeeds(): void
    {
        $receipt = $this->buildReceipt();
        $receipt->save();

        $this->assertDatabaseHas('transfer.transfer_receipts', [
            'id' => $receipt->id,
        ], 'platform');
    }

    public function test_save_on_existing_model_throws_immutable_exception(): void
    {
        $receipt = $this->buildReceipt();
        $receipt->save();

        $this->expectException(TransferReceiptImmutableException::class);

        $receipt->gross_amount = 9999;
        $receipt->save();
    }

    public function test_delete_throws_immutable_exception(): void
    {
        $receipt = $this->buildReceipt();
        $receipt->save();

        $this->expectException(TransferReceiptImmutableException::class);
        $receipt->delete();
    }

    public function test_update_throws_immutable_exception(): void
    {
        $receipt = $this->buildReceipt();
        $receipt->save();

        $this->expectException(TransferReceiptImmutableException::class);
        $receipt->update(['gross_amount' => 9999]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildReceipt(): TransferReceiptModel
    {
        $tx = new TransferTransactionModel();
        $tx->id                          = (string) Uuid::uuid7();
        $tx->transfer_reference          = 'TXF-IMMU123456';
        $tx->sender_customer_id          = $this->senderCustomerId;
        $tx->sender_wallet_id            = $this->senderWalletId;
        $tx->sender_pocket_ledger_ref    = $this->senderPocketRef;
        $tx->recipient_customer_id       = $this->recipientCustomerId;
        $tx->recipient_wallet_id         = $this->recipientWalletId;
        $tx->recipient_pocket_ledger_ref = $this->recipientPocketRef;
        $tx->currency_code               = $this->currencyCode;
        $tx->gross_amount                = 5000;
        $tx->fee_amount                  = 0;
        $tx->net_amount                  = 5000;
        $tx->fee_rate_bps                = 0;
        $tx->status                      = TransferStatus::Completed;
        $tx->failure_reason              = null;
        $tx->idempotency_key             = (string) Uuid::uuid7();
        $tx->correlation_id              = (string) Uuid::uuid7();
        $tx->save();

        $receipt = new TransferReceiptModel();
        $receipt->id                      = (string) Uuid::uuid7();
        $receipt->transfer_transaction_id = $tx->id;
        $receipt->receipt_reference       = (string) Uuid::uuid7();
        $receipt->sender_customer_id      = $this->senderCustomerId;
        $receipt->recipient_customer_id   = $this->recipientCustomerId;
        $receipt->currency_code           = $this->currencyCode;
        $receipt->gross_amount            = 5000;
        $receipt->fee_amount              = 0;
        $receipt->net_amount              = 5000;
        $receipt->issued_at               = now();

        return $receipt;
    }
}
