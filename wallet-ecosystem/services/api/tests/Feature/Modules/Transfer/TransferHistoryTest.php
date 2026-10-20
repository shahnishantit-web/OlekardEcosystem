<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Transfer;

use App\Modules\Transfer\Domain\Enums\TransferStatus;
use App\Modules\Transfer\Infrastructure\Models\TransferReceiptModel;
use App\Modules\Transfer\Infrastructure\Models\TransferTransactionModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Feature tests for US4 — Transfer History and Receipts.
 *
 * Tests:
 * - GET /api/v1/transfers — paginated list, filtered by status
 * - GET /api/v1/transfers/{id} — show single transfer
 * - GET /api/v1/transfers/{id}/receipt — show receipt by transfer ID
 * - GET /api/v1/transfers/receipts/{ref} — show receipt by reference
 * - IDOR: customer can only see their own transfers
 */
final class TransferHistoryTest extends TransferTestCase
{
    private object $senderActor;
    private object $recipientActor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->senderActor    = $this->makeReadActor($this->senderCustomerId);
        $this->recipientActor = $this->makeReadActor($this->recipientCustomerId);
    }

    public function test_index_returns_paginated_transfers_for_actor(): void
    {
        $tx = $this->createCompletedTransfer();

        $this->withActorToken($this->senderActor)
            ->getJson('/api/v1/transfers')
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'per_page', 'total', 'last_page']])
            ->assertJsonPath('data.0.id', $tx->id);
    }

    public function test_recipient_can_see_received_transfers_in_index(): void
    {
        $tx = $this->createCompletedTransfer();

        $this->withActorToken($this->recipientActor)
            ->getJson('/api/v1/transfers')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $tx->id);
    }

    public function test_index_filtered_by_status(): void
    {
        $this->createCompletedTransfer();

        $this->withActorToken($this->senderActor)
            ->getJson('/api/v1/transfers?status=pending')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 0);

        $this->withActorToken($this->senderActor)
            ->getJson('/api/v1/transfers?status=completed')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_show_returns_transfer_for_sender(): void
    {
        $tx = $this->createCompletedTransfer();

        $this->withActorToken($this->senderActor)
            ->getJson("/api/v1/transfers/{$tx->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $tx->id);
    }

    public function test_show_returns_404_for_unrelated_actor(): void
    {
        $tx       = $this->createCompletedTransfer();
        $stranger = $this->makeReadActor((string) Uuid::uuid7());

        $this->withActorToken($stranger)
            ->getJson("/api/v1/transfers/{$tx->id}")
            ->assertStatus(404);
    }

    public function test_receipt_by_transfer_id_returns_receipt(): void
    {
        [$tx, $receipt] = $this->createCompletedTransferWithReceipt();

        $this->withActorToken($this->senderActor)
            ->getJson("/api/v1/transfers/{$tx->id}/receipt")
            ->assertStatus(200)
            ->assertJsonPath('data.transfer_transaction_id', $tx->id);
    }

    public function test_receipt_by_reference_returns_receipt(): void
    {
        [$tx, $receipt] = $this->createCompletedTransferWithReceipt();

        $this->withActorToken($this->senderActor)
            ->getJson("/api/v1/transfers/receipts/{$receipt->receipt_reference}")
            ->assertStatus(200)
            ->assertJsonPath('data.receipt_reference', $receipt->receipt_reference);
    }

    public function test_receipt_by_reference_returns_404_for_unrelated_actor(): void
    {
        [$tx, $receipt] = $this->createCompletedTransferWithReceipt();
        $stranger       = $this->makeReadActor((string) Uuid::uuid7());

        $this->withActorToken($stranger)
            ->getJson("/api/v1/transfers/receipts/{$receipt->receipt_reference}")
            ->assertStatus(404);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createCompletedTransfer(): TransferTransactionModel
    {
        $tx = $this->buildTransfer();
        $tx->status = TransferStatus::Completed;
        $tx->save();
        return $tx;
    }

    private function createCompletedTransferWithReceipt(): array
    {
        $tx = $this->createCompletedTransfer();

        $receipt = new TransferReceiptModel();
        $receipt->id                      = (string) Uuid::uuid7();
        $receipt->transfer_transaction_id = $tx->id;
        $receipt->receipt_reference       = (string) Uuid::uuid7();
        $receipt->sender_customer_id      = $this->senderCustomerId;
        $receipt->recipient_customer_id   = $this->recipientCustomerId;
        $receipt->currency_code           = $this->currencyCode;
        $receipt->gross_amount            = $tx->gross_amount;
        $receipt->fee_amount              = $tx->fee_amount;
        $receipt->net_amount              = $tx->net_amount;
        $receipt->issued_at               = now();
        $receipt->save();

        return [$tx, $receipt];
    }

    private function buildTransfer(): TransferTransactionModel
    {
        $tx = new TransferTransactionModel();
        $tx->id                          = (string) Uuid::uuid7();
        $tx->transfer_reference          = 'TXF-HIST' . substr((string) Uuid::uuid7(), 0, 6);
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

    private function makeReadActor(string $customerId): object
    {
        $roleId = (string) Uuid::uuid7();

        DB::connection('identity')->table('identity.roles')->insert([
            'id'             => $roleId,
            'slug'           => 'read-role-' . substr($roleId, 0, 8),
            'display_name'   => 'Read Role',
            'is_system_role' => false,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        foreach (['transfer.read', 'transfer.initiate'] as $slug) {
            DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                'id'                   => (string) Uuid::uuid7(),
                'slug'                 => $slug,
                'display_name'         => $slug,
                'module'               => 'transfer',
                'is_system_permission' => false,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);

            $perm = DB::connection('identity')
                ->table('identity.permissions')
                ->where('slug', $slug)
                ->first(['id']);

            DB::connection('identity')->table('identity.role_permissions')->insertOrIgnore([
                'id'            => (string) Uuid::uuid7(),
                'role_id'       => $roleId,
                'permission_id' => $perm->id,
                'created_at'    => now(),
            ]);
        }

        if (DB::connection('identity')->table('identity.users')->where('id', $customerId)->exists()) {
            DB::connection('identity')->table('identity.users')
                ->where('id', $customerId)
                ->update(['default_role_id' => $roleId]);
        } else {
            DB::connection('identity')->table('identity.users')->insert([
                'id'              => $customerId,
                'email'           => 'hist-actor-' . $customerId . '@test.local',
                'mobile_number'   => '+55' . mt_rand(100000000, 999999999),
                'actor_type'      => 'CUSTOMER',
                'status'          => 'active',
                'default_role_id' => $roleId,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        return (object) ['id' => $customerId, 'actor_type' => 'CUSTOMER'];
    }
}
