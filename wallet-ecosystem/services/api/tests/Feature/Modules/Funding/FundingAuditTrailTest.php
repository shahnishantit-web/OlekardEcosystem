<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Funding;

use App\Modules\Funding\Domain\Enums\FundingMethod;
use App\Modules\Funding\Domain\Enums\FundingStatus;
use App\Modules\Funding\Infrastructure\Models\FundingTransactionModel;
use Illuminate\Support\Facades\Queue;

/**
 * Verifies that privileged and financial actions emit correct audit events (FR-E08-046).
 */
final class FundingAuditTrailTest extends FundingTestCase
{
    public function test_initiation_audit_event_recorded(): void
    {
        Queue::fake();
        $customer = $this->makeActorWithPermission('CUSTOMER', 'funding.initiate');

        $this->withActorToken($customer)
            ->postJson('/api/v1/funding/transactions', [
                'wallet_id'      => $this->walletId,
                'gross_amount'   => 10000,
                'currency_code'  => 'BWP',
                'funding_method' => FundingMethod::MobileMoney->value,
            ])
            ->assertStatus(201);

        $this->assertAuditEventRecorded('funding.transaction.initiated');
    }

    public function test_confirmation_audit_event_recorded(): void
    {
        Queue::fake();
        $customer = $this->makeActorWithPermission('CUSTOMER', 'funding.initiate');

        $initResp = $this->withActorToken($customer)
            ->postJson('/api/v1/funding/transactions', [
                'wallet_id'      => $this->walletId,
                'gross_amount'   => 10000,
                'currency_code'  => 'BWP',
                'funding_method' => FundingMethod::MobileMoney->value,
            ])
            ->assertStatus(201);

        $txId = $initResp->json('data.id');
        $fee  = $initResp->json('data.fee_amount');

        $this->withActorToken($customer)
            ->postJson("/api/v1/funding/transactions/{$txId}/confirm", [
                'transaction_pin'     => '1234',
                'accepted_fee_amount' => $fee,
            ])
            ->assertStatus(200);

        $this->assertAuditEventRecorded('funding.transaction.confirmed');
    }

    public function test_cancellation_audit_event_recorded(): void
    {
        Queue::fake();
        $customer = $this->makeActorWithPermission('CUSTOMER', 'funding.initiate');

        $initResp = $this->withActorToken($customer)
            ->postJson('/api/v1/funding/transactions', [
                'wallet_id'      => $this->walletId,
                'gross_amount'   => 10000,
                'currency_code'  => 'BWP',
                'funding_method' => FundingMethod::MobileMoney->value,
            ])
            ->assertStatus(201);

        $txId = $initResp->json('data.id');

        $this->withActorToken($customer)
            ->postJson("/api/v1/funding/transactions/{$txId}/cancel")
            ->assertStatus(200);

        $this->assertAuditEventRecorded('funding.transaction.cancelled');
    }

    public function test_agent_cashin_audit_event_recorded(): void
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
}
