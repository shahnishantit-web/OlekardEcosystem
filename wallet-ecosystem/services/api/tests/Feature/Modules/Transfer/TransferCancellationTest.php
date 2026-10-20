<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Transfer;

use App\Modules\Transfer\Application\Services\LookupTokenService;
use App\Modules\Transfer\Domain\Enums\TransferStatus;
use App\Modules\Transfer\Infrastructure\Models\TransferTransactionModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Feature tests for US3 — Transfer Cancellation.
 *
 * Tests POST /api/v1/transfers/{id}/cancel covering:
 * - Happy path: pending transfer cancelled
 * - Already terminal transfer returns 422
 * - IDOR: cannot cancel another customer's transfer
 * - Audit event recorded on cancellation
 */
final class TransferCancellationTest extends TransferTestCase
{
    private object $senderActor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->senderActor = $this->makeTransferActor($this->senderCustomerId);
        $this->creditSenderBalance(200000);
    }

    public function test_happy_path_cancels_pending_transfer(): void
    {
        $tx = $this->initiatePendingTransfer(10000);

        $this->withActorToken($this->senderActor)
            ->postJson("/api/v1/transfers/{$tx->id}/cancel", [])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(
            TransferStatus::Cancelled->value,
            TransferTransactionModel::find($tx->id)->status->value
        );
    }

    public function test_cancelling_completed_transfer_returns_422(): void
    {
        $tx         = $this->initiatePendingTransfer(10000);
        $tx->status = TransferStatus::Completed;
        $tx->save();

        $this->withActorToken($this->senderActor)
            ->postJson("/api/v1/transfers/{$tx->id}/cancel", [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TRANSFER_NOT_CANCELLABLE');
    }

    public function test_idor_cannot_cancel_another_customers_transfer(): void
    {
        $tx       = $this->initiatePendingTransfer(10000);
        $attacker = $this->makeTransferActor((string) Uuid::uuid7());

        $this->withActorToken($attacker)
            ->postJson("/api/v1/transfers/{$tx->id}/cancel", [])
            ->assertStatus(404);
    }

    public function test_cancellation_records_audit_event(): void
    {
        $tx = $this->initiatePendingTransfer(10000);

        $this->withActorToken($this->senderActor)
            ->postJson("/api/v1/transfers/{$tx->id}/cancel", [])
            ->assertStatus(200);

        $this->assertAuditEventRecorded('transfer.cancelled');
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
                'email'           => 'cancel-actor-' . $customerId . '@test.local',
                'mobile_number'   => '+77' . mt_rand(100000000, 999999999),
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
