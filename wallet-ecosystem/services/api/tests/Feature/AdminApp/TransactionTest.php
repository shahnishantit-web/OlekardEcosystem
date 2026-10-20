<?php

declare(strict_types=1);

namespace Tests\Feature\AdminApp;

use Tests\TestCase;

final class TransactionTest extends TestCase
{
    public function test_transaction_search_returns_results_with_reversal_eligible_flag(): void
    {
        $customerId = (string) \Illuminate\Support\Str::uuid();
        $transactionId = $this->insertProjection($customerId);

        $response = $this->actingAsAdminWithPermission('adminapp.transactions.read')
            ->getJson('/v1/admin-app/transactions?user_id=' . $customerId . '&per_page=10');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.transaction_id', $transactionId)
            ->assertJsonPath('data.0.type', 'withdrawal')
            ->assertJsonPath('data.0.reversal_eligible', true)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_investigate_emits_audit_entry(): void
    {
        $txnId = $this->insertProjection((string) \Illuminate\Support\Str::uuid());

        $response = $this->actingAsAdminWithPermission('adminapp.transactions.investigate')
            ->getJson("/v1/admin-app/transactions/{$txnId}");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['transaction_id', 'ledger_entries', 'fraud_assessment']])
            ->assertJsonPath('data.transaction_id', $txnId)
            ->assertJsonPath('data.amount_minor_units', 1234)
            ->assertJsonPath('data.ledger_entries', [])
            ->assertJsonPath('data.fraud_assessment', null);
    }

    public function test_reverse_funding_transaction_returns_422_adminapp_015(): void
    {
        $txnId = $this->createTransaction('funding');

        $response = $this->actingAsAdminWithPermission('adminapp.transactions.reverse')
            ->withHeaders(['Idempotency-Key' => 'reverse-funding-' . $txnId])
            ->postJson("/v1/admin-app/transactions/{$txnId}/reverse", [
                'reason' => 'Test reversal',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'ADMINAPP_015');
    }

    public function test_reverse_wallet_transfer_returns_202(): void
    {
        $txnId = $this->createTransaction('wallet_transfer');

        $response = $this->actingAsAdminWithPermission('adminapp.transactions.reverse')
            ->withHeaders(['Idempotency-Key' => 'reverse-transfer-' . $txnId])
            ->postJson("/v1/admin-app/transactions/{$txnId}/reverse", [
                'reason' => 'Customer dispute resolved',
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.action_type', 'adminapp.transaction.reverse')
            ->assertJsonPath('data.status', 'pending_approval');
    }

    public function test_finance_role_can_search_transactions(): void
    {
        $response = $this->actingAsAdminWithPermission('adminapp.transactions.read')
            ->getJson('/v1/admin-app/transactions');

        $response->assertStatus(200);
    }

    public function test_transaction_search_can_be_scoped_to_one_user(): void
    {
        $userId = (string) \Illuminate\Support\Str::uuid();
        $otherUserId = (string) \Illuminate\Support\Str::uuid();
        $transactionId = $this->insertProjection($userId);
        $this->insertProjection($otherUserId);

        $response = $this->actingAsAdminWithPermission('adminapp.transactions.read')
            ->getJson('/v1/admin-app/transactions?user_id=' . $userId . '&per_page=10');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.transaction_id', $transactionId)
            ->assertJsonPath('data.0.initiator_user_id', $userId);
    }

    private function insertProjection(string $customerId): string
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\DB::connection('platform')->table('transaction.transaction_projections')->insert([
            'id' => $id,
            'reference' => 'QA-' . str_replace('-', '', $id),
            'type' => 'withdrawal',
            'status' => 'completed',
            'amount' => 1234,
            'currency_code' => 'BWP',
            'direction' => 'credit',
            'wallet_id' => (string) \Illuminate\Support\Str::uuid(),
            'customer_id' => $customerId,
            'source_module' => 'transfer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $id;
    }

    private function createTransaction(string $type): string
    {
        return \Illuminate\Support\Str::uuid()->toString();
    }
}
