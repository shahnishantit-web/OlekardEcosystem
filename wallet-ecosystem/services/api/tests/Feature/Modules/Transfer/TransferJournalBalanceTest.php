<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Transfer;

use App\Modules\Transfer\Application\Services\LookupTokenService;
use App\Modules\Transfer\Infrastructure\Models\TransferTransactionModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Feature tests for T059: double-entry journal balance verification.
 *
 * Asserts:
 * - For fee > 0: 3 journal lines, SUM(debit) = SUM(credit) = gross_amount
 * - For fee = 0: 2 journal lines, SUM(debit) = SUM(credit) = gross_amount
 * - ledger_journal_id IS NOT NULL after completion
 * - No journal entry for failed / cancelled transfers
 */
final class TransferJournalBalanceTest extends TransferTestCase
{
    private object $senderActor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->senderActor = $this->makeFullActor($this->senderCustomerId);
        $this->creditSenderBalance(500000);
    }

    public function test_zero_fee_journal_has_two_balanced_lines(): void
    {
        // Default platform config has fee_rate=0 → zero fee
        $tx = $this->confirmTransfer(10000);

        $this->assertNotNull($tx->ledger_journal_id, 'journal_id must be set');

        $lines = DB::connection('platform')
            ->table('ledger.journal_lines')
            ->where('journal_id', $tx->ledger_journal_id)
            ->get();

        $this->assertCount(2, $lines);

        $debits  = $lines->where('direction', 'debit')->sum('amount');
        $credits = $lines->where('direction', 'credit')->sum('amount');

        $this->assertSame((int) $debits, (int) $credits);
        $this->assertSame($tx->gross_amount, (int) $debits);
    }

    public function test_non_zero_fee_journal_has_three_balanced_lines(): void
    {
        // Temporarily set fee rate to 150 bps
        DB::connection('platform')->table('platform.configuration_values')
            ->where('key', 'transfer.fee.rate_bps')
            ->update(['value' => '150']);

        $tx = $this->confirmTransfer(10000);

        DB::connection('platform')->table('platform.configuration_values')
            ->where('key', 'transfer.fee.rate_bps')
            ->update(['value' => '0']);

        $this->assertNotNull($tx->ledger_journal_id);

        $lines = DB::connection('platform')
            ->table('ledger.journal_lines')
            ->where('journal_id', $tx->ledger_journal_id)
            ->get();

        $this->assertCount(3, $lines);

        $debits  = $lines->where('direction', 'debit')->sum('amount');
        $credits = $lines->where('direction', 'credit')->sum('amount');

        $this->assertSame((int) $debits, (int) $credits);
        $this->assertSame($tx->gross_amount, (int) $debits);
    }

    public function test_cancelled_transfer_has_no_journal(): void
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
                'gross_amount'  => 5000,
                'currency_code' => $this->currencyCode,
            ]);

        $r->assertStatus(201);
        $txId = $r->json('data.id');

        $this->withActorToken($this->senderActor)
            ->postJson("/api/v1/transfers/{$txId}/cancel", [])
            ->assertStatus(200);

        $tx = TransferTransactionModel::find($txId);
        $this->assertNull($tx->ledger_journal_id, 'Cancelled transfer must not have a journal_id');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function confirmTransfer(int $grossAmount): TransferTransactionModel
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
        $txId = $r->json('data.id');

        $this->withActorToken($this->senderActor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson("/api/v1/transfers/{$txId}/confirm", [
                'transaction_pin' => 'password',
            ])
            ->assertStatus(200);

        return TransferTransactionModel::find($txId);
    }

    private function makeFullActor(string $customerId): object
    {
        $roleId = (string) Uuid::uuid7();

        DB::connection('identity')->table('identity.roles')->insert([
            'id'             => $roleId,
            'slug'           => 'journal-role-' . substr($roleId, 0, 8),
            'display_name'   => 'Journal Test Role',
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
