<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Transfer;

use App\Modules\Transfer\Domain\Enums\TransferStatus;
use App\Modules\Transfer\Infrastructure\Models\TransferTransactionModel;
use Ramsey\Uuid\Uuid;

/**
 * Feature tests for Admin Transfer endpoints.
 *
 * Tests:
 * - GET /api/v1/admin/transfers — list all transfers
 * - GET /api/v1/admin/transfers/{id} — show any transfer
 * - POST /api/v1/admin/transfers/{id}/force-fail — force-fail a pending transfer
 * - force-fail already terminal transfer returns 422
 * - force-fail records audit event
 */
final class AdminTransferTest extends TransferTestCase
{
    private object $adminActor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminActor = $this->makeAdminWithPermission('transfer.admin');
    }

    public function test_admin_index_returns_all_transfers(): void
    {
        $tx = $this->createTransfer();

        $this->withActorToken($this->adminActor)
            ->getJson('/api/v1/admin/transfers')
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta'])
            ->assertJsonPath('meta.total', 1);
    }

    public function test_admin_show_returns_any_transfer(): void
    {
        $tx = $this->createTransfer();

        $this->withActorToken($this->adminActor)
            ->getJson("/api/v1/admin/transfers/{$tx->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $tx->id);
    }

    public function test_admin_show_returns_404_for_unknown_id(): void
    {
        $this->withActorToken($this->adminActor)
            ->getJson('/api/v1/admin/transfers/' . (string) Uuid::uuid7())
            ->assertStatus(404);
    }

    public function test_force_fail_transitions_pending_to_failed(): void
    {
        $tx = $this->createTransfer();

        $this->withActorToken($this->adminActor)
            ->postJson("/api/v1/admin/transfers/{$tx->id}/force-fail", [
                'reason' => 'Compliance hold',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'failed');

        $this->assertSame(
            TransferStatus::Failed->value,
            TransferTransactionModel::find($tx->id)->status->value
        );
    }

    public function test_force_fail_already_terminal_returns_422(): void
    {
        $tx         = $this->createTransfer();
        $tx->status = TransferStatus::Completed;
        $tx->save();

        $this->withActorToken($this->adminActor)
            ->postJson("/api/v1/admin/transfers/{$tx->id}/force-fail", [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TRANSFER_ALREADY_TERMINAL');
    }

    public function test_force_fail_records_audit_event(): void
    {
        $tx = $this->createTransfer();

        $this->withActorToken($this->adminActor)
            ->postJson("/api/v1/admin/transfers/{$tx->id}/force-fail", [])
            ->assertStatus(200);

        $this->assertAuditEventRecorded('transfer.admin.force_failed');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createTransfer(): TransferTransactionModel
    {
        $tx = new TransferTransactionModel();
        $tx->id                          = (string) Uuid::uuid7();
        $tx->transfer_reference          = 'TXF-ADMIN' . substr((string) Uuid::uuid7(), 0, 6);
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
        $tx->status                      = TransferStatus::Pending;
        $tx->failure_reason              = null;
        $tx->idempotency_key             = (string) Uuid::uuid7();
        $tx->correlation_id              = (string) Uuid::uuid7();
        $tx->save();
        return $tx;
    }
}
