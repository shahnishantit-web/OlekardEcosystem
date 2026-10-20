<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Transfer;

use App\Modules\Transfer\Application\Services\LookupTokenService;
use App\Modules\Transfer\Domain\Enums\TransferStatus;
use App\Modules\Transfer\Infrastructure\Models\TransferTransactionModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Feature tests for US2 — Transfer Confirmation.
 *
 * Tests POST /api/v1/transfers/{id}/confirm covering:
 * - Happy path: transfer completed, journal posted, receipt created
 * - Wrong PIN returns 422, transfer stays pending
 * - Transfer already terminal returns 422
 * - Fee mismatch between initiation and confirmation returns 409
 * - IDOR: cannot confirm another customer's transfer
 * - Confirmation is idempotent
 * - Audit events recorded on success and PIN failure
 */
final class TransferConfirmationTest extends TransferTestCase
{
    private object $senderActor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->senderActor = $this->makeTransferActor($this->senderCustomerId);
        $this->creditSenderBalance(200000);
    }

    public function test_happy_path_completes_transfer_and_creates_receipt(): void
    {
        $tx = $this->initiatePendingTransfer(10000);

        $response = $this->withActorToken($this->senderActor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson("/api/v1/transfers/{$tx->id}/confirm", [
                'transaction_pin' => 'password',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonStructure(['data' => ['id', 'status'], 'receipt' => ['receipt_reference']]);

        // Journal must be posted
        $this->assertDatabaseHas('ledger.journals', [
            'idempotency_key' => 'transfer_confirm:' . $tx->id,
            'status'          => 'posted',
        ], 'platform');

        // Receipt must exist
        $this->assertDatabaseHas('transfer.transfer_receipts', [
            'transfer_transaction_id' => $tx->id,
        ], 'platform');
    }

    public function test_wrong_pin_returns_422_and_transfer_stays_pending(): void
    {
        $tx = $this->initiatePendingTransfer(10000);

        $this->withActorToken($this->senderActor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson("/api/v1/transfers/{$tx->id}/confirm", [
                'transaction_pin' => 'wrong-pin',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_PIN');

        // Transfer must still be pending
        $this->assertSame(
            TransferStatus::Pending->value,
            TransferTransactionModel::find($tx->id)->status->value
        );
    }

    public function test_already_terminal_returns_422(): void
    {
        $tx         = $this->initiatePendingTransfer(10000);
        $tx->status = TransferStatus::Cancelled;
        $tx->save();

        $this->withActorToken($this->senderActor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson("/api/v1/transfers/{$tx->id}/confirm", [
                'transaction_pin' => 'password',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TRANSFER_NOT_MODIFIABLE');
    }

    public function test_idor_cannot_confirm_another_customers_transfer(): void
    {
        $tx       = $this->initiatePendingTransfer(10000);
        $attacker = $this->makeTransferActor((string) Uuid::uuid7());

        $this->withActorToken($attacker)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson("/api/v1/transfers/{$tx->id}/confirm", [
                'transaction_pin' => 'password',
            ])
            ->assertStatus(422);
    }

    public function test_fee_mismatch_returns_409_and_transfer_stays_pending(): void
    {
        $tx = $this->initiatePendingTransfer(10000);

        // Simulate fee rate change between initiation and confirmation
        $tx->fee_rate_bps = 999;
        $tx->save();

        $this->withActorToken($this->senderActor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson("/api/v1/transfers/{$tx->id}/confirm", [
                'transaction_pin' => 'password',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'FEE_MISMATCH')
            ->assertJsonStructure(['error' => ['new_fee_amount', 'new_net_amount', 'new_fee_rate_bps']]);

        $this->assertSame(
            TransferStatus::Pending->value,
            TransferTransactionModel::find($tx->id)->status->value
        );
    }

    public function test_confirmation_idempotency_key_required(): void
    {
        $tx = $this->initiatePendingTransfer(10000);

        $this->withActorToken($this->senderActor)
            ->postJson("/api/v1/transfers/{$tx->id}/confirm", [
                'transaction_pin' => 'password',
            ])
            ->assertStatus(422);
    }

    public function test_successful_confirmation_records_audit_event(): void
    {
        $tx = $this->initiatePendingTransfer(10000);

        $this->withActorToken($this->senderActor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson("/api/v1/transfers/{$tx->id}/confirm", [
                'transaction_pin' => 'password',
            ])
            ->assertStatus(200);

        $this->assertAuditEventRecorded('transfer.confirmed');
    }

    public function test_pin_failure_records_audit_event(): void
    {
        $tx = $this->initiatePendingTransfer(10000);

        $this->withActorToken($this->senderActor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson("/api/v1/transfers/{$tx->id}/confirm", [
                'transaction_pin' => 'wrong',
            ])
            ->assertStatus(422);

        $this->assertAuditEventRecorded('transfer.confirm.pin_failed');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function initiatePendingTransfer(int $grossAmount): TransferTransactionModel
    {
        $tokenService = app(LookupTokenService::class);
        $token        = $tokenService->issue(
            recipientCustomerId: $this->recipientCustomerId,
            recipientWalletId:   $this->recipientWalletId,
            pocketLedgerRef:     $this->recipientPocketRef,
            currencyCode:        $this->currencyCode,
        );

        $response = $this->withActorToken($this->senderActor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/transfers', [
                'lookup_token'  => $token,
                'wallet_id'     => $this->senderWalletId,
                'gross_amount'  => $grossAmount,
                'currency_code' => $this->currencyCode,
            ]);

        $response->assertStatus(201);

        return TransferTransactionModel::find($response->json('data.id'));
    }

    private function makeTransferActor(string $customerId): object
    {
        $roleId = (string) Uuid::uuid7();

        DB::connection('identity')->table('identity.roles')->insert([
            'id'             => $roleId,
            'slug'           => 'role-' . substr($roleId, 0, 8),
            'display_name'   => 'Transfer Role',
            'is_system_role' => false,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        foreach (['transfer.initiate', 'transfer.read'] as $slug) {
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
                'email'           => 'actor-confirm-' . $customerId . '@test.local',
                'mobile_number'   => '+88' . mt_rand(100000000, 999999999),
                'actor_type'      => 'CUSTOMER',
                'status'          => 'active',
                'default_role_id' => $roleId,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        return (object) [
            'id'         => $customerId,
            'actor_type' => 'CUSTOMER',
        ];
    }
}
