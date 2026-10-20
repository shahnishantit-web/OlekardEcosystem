<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Ledger;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\LedgerTestCase;

/**
 * T068 — US-LEDGER-010: Paginated account history (journal lines) with IDOR prevention.
 */
final class AccountHistoryTest extends LedgerTestCase
{
    private object $admin;
    private string $targetRef;
    private string $oppositeRef;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin      = $this->makeAdminWithPermission('ledger.post', 'ledger.read', 'ledger.read.any');
        $this->targetRef  = 'history-target-' . substr((string) Uuid::uuid7(), 0, 8);
        $this->oppositeRef = 'history-opp-' . substr((string) Uuid::uuid7(), 0, 8);
    }

    private function postEntry(string $debitRef, string $creditRef, int $amount): void
    {
        $this->withActorToken($this->admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/debit', [
                'currency_code'  => 'KES',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => $debitRef,  'direction' => 'debit',  'amount' => $amount],
                    ['ledger_account_reference' => $creditRef, 'direction' => 'credit', 'amount' => $amount],
                ],
            ])
            ->assertStatus(201);
    }

    // Scenario (a): 20 lines on account → page=1, per_page=10 → 10 items, meta.total=20, meta.pages=2.
    public function test_pagination_returns_correct_page_size_and_meta(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postEntry($this->targetRef, $this->oppositeRef, 500);
            $this->postEntry($this->oppositeRef, $this->targetRef, 300);
        }

        $response = $this->withActorToken($this->admin)
            ->getJson('/v1/ledger/accounts/' . $this->targetRef . '/entries?page=1&per_page=10');

        $response->assertStatus(200)
                 ->assertJsonCount(10, 'data')
                 ->assertJsonPath('meta.total', 20)
                 ->assertJsonPath('meta.page', 1)
                 ->assertJsonPath('meta.per_page', 10)
                 ->assertJsonPath('meta.pages', 2);
    }

    // Scenario (b): account with 0 lines → empty data array, meta.total=0, HTTP 200 (not 404).
    public function test_account_with_zero_lines_returns_empty_array(): void
    {
        $emptyRef = 'history-empty-' . substr((string) Uuid::uuid7(), 0, 8);

        DB::connection('platform')->table('ledger.ledger_accounts')->insert([
            'id'                       => (string) Uuid::uuid7(),
            'ledger_account_reference' => $emptyRef,
            'account_type'             => 'asset',
            'normal_balance'           => 'debit',
            'currency_code'            => 'KES',
            'owner_reference'          => null,
            'owner_type'               => null,
            'status'                   => 'active',
            'created_by'               => $this->admin->id,
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        $response = $this->withActorToken($this->admin)
            ->getJson('/v1/ledger/accounts/' . $emptyRef . '/entries');

        $response->assertStatus(200)
                 ->assertJsonCount(0, 'data')
                 ->assertJsonPath('meta.total', 0)
                 ->assertJsonPath('meta.pages', 0);
    }

    // Scenario (c): without ledger.read permission → 403.
    public function test_missing_ledger_read_permission_returns_403(): void
    {
        $actor = $this->makeAdminWithPermission('ledger.post');

        $this->postEntry($this->targetRef, $this->oppositeRef, 1000);

        $this->withActorToken($actor)
            ->getJson('/v1/ledger/accounts/' . $this->targetRef . '/entries')
            ->assertStatus(403);
    }

    // Scenario (d): actor without ledger.read.any querying an account owned by another actor → 404 IDOR.
    public function test_idor_returns_404_for_non_owner_without_read_any(): void
    {
        $owner  = $this->makeAdminWithPermission('ledger.post', 'ledger.read');
        $other  = $this->makeAdminWithPermission('ledger.read');

        // Register account owned by $owner
        $ownedRef = 'idor-owned-' . substr((string) Uuid::uuid7(), 0, 8);
        DB::connection('platform')->table('ledger.ledger_accounts')->insert([
            'id'                       => (string) Uuid::uuid7(),
            'ledger_account_reference' => $ownedRef,
            'account_type'             => 'liability',
            'normal_balance'           => 'credit',
            'currency_code'            => 'KES',
            'owner_reference'          => $owner->id,
            'owner_type'               => 'user',
            'status'                   => 'active',
            'created_by'               => $owner->id,
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        // $other does NOT have ledger.read.any and is not the owner
        $this->withActorToken($other)
            ->getJson('/v1/ledger/accounts/' . $ownedRef . '/entries')
            ->assertStatus(404);
    }

    // Scenario (e): each line includes all required fields.
    public function test_each_line_includes_required_fields(): void
    {
        $txnRef = 'TXN-HIST-' . strtoupper(substr((string) Uuid::uuid7(), 0, 8));

        $this->withActorToken($this->admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/debit', [
                'currency_code'         => 'KES',
                'correlation_id'        => (string) Uuid::uuid7(),
                'transaction_reference' => $txnRef,
                'lines'                 => [
                    ['ledger_account_reference' => $this->targetRef,  'direction' => 'debit',  'amount' => 2500],
                    ['ledger_account_reference' => $this->oppositeRef, 'direction' => 'credit', 'amount' => 2500],
                ],
            ])
            ->assertStatus(201);

        $response = $this->withActorToken($this->admin)
            ->getJson('/v1/ledger/accounts/' . $this->targetRef . '/entries');

        $response->assertStatus(200)
                 ->assertJsonCount(1, 'data');

        $line = $response->json('data.0');
        $this->assertArrayHasKey('id',                    $line);
        $this->assertArrayHasKey('journal_id',            $line);
        $this->assertArrayHasKey('entry_type',            $line);
        $this->assertArrayHasKey('direction',             $line);
        $this->assertArrayHasKey('amount',                $line);
        $this->assertArrayHasKey('currency_code',         $line);
        $this->assertArrayHasKey('transaction_reference', $line);
        $this->assertArrayHasKey('posted_at',             $line);

        $this->assertSame(2500,    $line['amount']);
        $this->assertSame('KES',   $line['currency_code']);
        $this->assertSame('debit', $line['direction']);
        $this->assertSame($txnRef, $line['transaction_reference']);
    }

    // Scenario (f): per_page capped at 100.
    public function test_per_page_is_capped_at_100(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postEntry($this->targetRef, $this->oppositeRef, 100);
        }

        $response = $this->withActorToken($this->admin)
            ->getJson('/v1/ledger/accounts/' . $this->targetRef . '/entries?per_page=500');

        $response->assertStatus(200)
                 ->assertJsonPath('meta.per_page', 100);
    }
}
