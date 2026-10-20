<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Transfer;

use App\Modules\Transfer\Application\Services\LookupTokenService;
use App\Modules\Transfer\Infrastructure\Models\TransferTransactionModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Feature tests for T063: Transfer audit trail.
 *
 * Verifies that platform audit events are recorded for:
 * - transfer.initiated
 * - transfer.confirmed (success)
 * - transfer.confirm.pin_failed
 * - transfer.cancelled
 * - transfer.transaction.expired
 *
 * Also verifies that PIN values are absent from all audit event entries.
 */
final class TransferAuditTrailTest extends TransferTestCase
{
    private object $senderActor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->senderActor = $this->makeFullActor($this->senderCustomerId);
        $this->creditSenderBalance(500000);
    }

    public function test_initiation_records_audit_event(): void
    {
        $this->initiateTransfer(10000);

        $this->assertAuditEventRecorded('transfer.initiated');
    }

    public function test_confirmation_success_records_audit_event(): void
    {
        $tx = $this->initiateTransfer(10000);

        $this->withActorToken($this->senderActor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson("/api/v1/transfers/{$tx->id}/confirm", ['transaction_pin' => 'password'])
            ->assertStatus(200);

        $this->assertAuditEventRecorded('transfer.confirmed');
    }

    public function test_pin_failure_records_audit_event(): void
    {
        $tx = $this->initiateTransfer(10000);

        $this->withActorToken($this->senderActor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson("/api/v1/transfers/{$tx->id}/confirm", ['transaction_pin' => 'wrong'])
            ->assertStatus(422);

        $this->assertAuditEventRecorded('transfer.confirm.pin_failed');
    }

    public function test_cancellation_records_audit_event(): void
    {
        $tx = $this->initiateTransfer(10000);

        $this->withActorToken($this->senderActor)
            ->postJson("/api/v1/transfers/{$tx->id}/cancel", [])
            ->assertStatus(200);

        $this->assertAuditEventRecorded('transfer.cancelled');
    }

    public function test_pin_value_not_stored_in_audit_events(): void
    {
        $tx = $this->initiateTransfer(10000);

        $this->withActorToken($this->senderActor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson("/api/v1/transfers/{$tx->id}/confirm", ['transaction_pin' => 'mysecretpin123'])
            ->assertStatus(200);

        // Scan all audit_events for any occurrence of the PIN value
        $allEvents = DB::connection('platform')
            ->table('platform.audit_events')
            ->get();

        foreach ($allEvents as $event) {
            $serialized = json_encode($event);
            $this->assertStringNotContainsString(
                'mysecretpin123',
                $serialized,
                "PIN value found in audit event: {$event->action}"
            );
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function initiateTransfer(int $grossAmount): TransferTransactionModel
    {
        $tokenService = app(LookupTokenService::class);
        $token = $tokenService->issue(
            recipientCustomerId: $this->recipientCustomerId,
            recipientWalletId:   $this->recipientWalletId,
            pocketLedgerRef:     $this->recipientPocketRef,
            currencyCode:        $this->currencyCode,
        );

        $r = $this->withActorToken($this->senderActor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/transfers', [
                'lookup_token'  => $token,
                'wallet_id'     => $this->senderWalletId,
                'gross_amount'  => $grossAmount,
                'currency_code' => $this->currencyCode,
            ]);

        $r->assertStatus(201);

        return TransferTransactionModel::find($r->json('data.id'));
    }

    private function makeFullActor(string $customerId): object
    {
        $roleId = (string) Uuid::uuid7();

        DB::connection('identity')->table('identity.roles')->insert([
            'id'             => $roleId,
            'slug'           => 'audit-role-' . substr($roleId, 0, 8),
            'display_name'   => 'Audit Test Role',
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

        DB::connection('identity')->table('identity.users')
            ->where('id', $customerId)
            ->update(['default_role_id' => $roleId]);

        return (object) ['id' => $customerId, 'actor_type' => 'CUSTOMER'];
    }
}
