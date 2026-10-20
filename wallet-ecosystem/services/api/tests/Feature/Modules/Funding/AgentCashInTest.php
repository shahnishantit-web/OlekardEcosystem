<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Funding;

use App\Modules\Funding\Domain\Enums\FundingMethod;
use App\Modules\Funding\Domain\Enums\FundingStatus;

final class AgentCashInTest extends FundingTestCase
{
    // ── FR-E08-036 — FR-E08-044: Agent cash-in ──────────────────────────────

    public function test_agent_can_cash_in_to_customer_wallet(): void
    {
        $idempotencyKey = (string) \Ramsey\Uuid\Uuid::uuid7();
        $agent = $this->makeActorWithPermission('AGENT', 'funding.agent-cashin');

        $response = $this->withActorToken($agent)
            ->postJson('/api/v1/funding/agent-transactions', [
                'customer_id'     => $this->customerId,
                'wallet_id'       => $this->walletId,
                'amount'          => 5000,
                'currency_code'   => 'BWP',
                'transaction_pin' => '1234',
            ], ['X-Idempotency-Key' => $idempotencyKey]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', FundingStatus::Completed->value)
            ->assertJsonPath('data.fee_amount', 0)
            ->assertJsonPath('data.funding_method', FundingMethod::Agent->value);
    }

    public function test_agent_cashin_fee_is_always_zero(): void
    {
        $agent = $this->makeActorWithPermission('AGENT', 'funding.agent-cashin');

        $response = $this->withActorToken($agent)
            ->postJson('/api/v1/funding/agent-transactions', [
                'customer_id'     => $this->customerId,
                'wallet_id'       => $this->walletId,
                'amount'          => 20000,
                'currency_code'   => 'BWP',
                'transaction_pin' => '1234',
            ], ['X-Idempotency-Key' => (string) \Ramsey\Uuid\Uuid::uuid7()]);

        $response->assertStatus(201)
            ->assertJsonPath('data.fee_amount', 0)
            ->assertJsonPath('data.net_amount', 20000);
    }

    public function test_agent_cashin_posts_correct_double_entry_journal(): void
    {
        $agent = $this->makeActorWithPermission('AGENT', 'funding.agent-cashin');

        $this->withActorToken($agent)
            ->postJson('/api/v1/funding/agent-transactions', [
                'customer_id'     => $this->customerId,
                'wallet_id'       => $this->walletId,
                'amount'          => 5000,
                'currency_code'   => 'BWP',
                'transaction_pin' => '1234',
            ], ['X-Idempotency-Key' => (string) \Ramsey\Uuid\Uuid::uuid7()])
            ->assertStatus(201);

        // Journal line: debit agent float, credit customer pocket
        $this->assertDatabaseHas('ledger.journal_lines', [
            'ledger_account_reference' => $this->agentFloatRef,
            'direction'                => 'debit',
            'amount'                   => 5000,
        ]);

        $this->assertDatabaseHas('ledger.journal_lines', [
            'ledger_account_reference' => $this->pocketLedgerRef,
            'direction'                => 'credit',
            'amount'                   => 5000,
        ]);
    }

    public function test_agent_cashin_fails_with_insufficient_float(): void
    {
        $agent = $this->makeActorWithPermission('AGENT', 'funding.agent-cashin');

        $response = $this->withActorToken($agent)
            ->postJson('/api/v1/funding/agent-transactions', [
                'customer_id'     => $this->customerId,
                'wallet_id'       => $this->walletId,
                'amount'          => 100_000_000, // exceeds seeded float
                'currency_code'   => 'BWP',
                'transaction_pin' => '1234',
            ], ['X-Idempotency-Key' => (string) \Ramsey\Uuid\Uuid::uuid7()]);

        $response->assertStatus(422);
    }

    public function test_agent_cashin_is_idempotent(): void
    {
        $agent = $this->makeActorWithPermission('AGENT', 'funding.agent-cashin');
        $key   = (string) \Ramsey\Uuid\Uuid::uuid7();

        $first = $this->withActorToken($agent)
            ->postJson('/api/v1/funding/agent-transactions', [
                'customer_id'     => $this->customerId,
                'wallet_id'       => $this->walletId,
                'amount'          => 5000,
                'currency_code'   => 'BWP',
                'transaction_pin' => '1234',
            ], ['X-Idempotency-Key' => $key]);

        $second = $this->withActorToken($agent)
            ->postJson('/api/v1/funding/agent-transactions', [
                'customer_id'     => $this->customerId,
                'wallet_id'       => $this->walletId,
                'amount'          => 5000,
                'currency_code'   => 'BWP',
                'transaction_pin' => '1234',
            ], ['X-Idempotency-Key' => $key]);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
    }

    public function test_audit_event_recorded_for_agent_cashin(): void
    {
        $agent = $this->makeActorWithPermission('AGENT', 'funding.agent-cashin');

        $this->withActorToken($agent)
            ->postJson('/api/v1/funding/agent-transactions', [
                'customer_id'     => $this->customerId,
                'wallet_id'       => $this->walletId,
                'amount'          => 5000,
                'currency_code'   => 'BWP',
                'transaction_pin' => '1234',
            ], ['X-Idempotency-Key' => (string) \Ramsey\Uuid\Uuid::uuid7()])
            ->assertStatus(201);

        $this->assertAuditEventRecorded('funding.agent-cashin.completed');
    }

    public function test_agent_without_permission_is_rejected(): void
    {
        $agent = $this->makeActorWithPermission('AGENT'); // no funding.agent-cashin

        $this->withActorToken($agent)
            ->postJson('/api/v1/funding/agent-transactions', [
                'customer_id'     => $this->customerId,
                'wallet_id'       => $this->walletId,
                'amount'          => 5000,
                'currency_code'   => 'BWP',
                'transaction_pin' => '1234',
            ], ['X-Idempotency-Key' => (string) \Ramsey\Uuid\Uuid::uuid7()])
            ->assertStatus(403);
    }
}
