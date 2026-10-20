<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Funding;

use App\Modules\Funding\Domain\Enums\FundingMethod;
use App\Modules\Funding\Domain\Enums\FundingStatus;
use App\Modules\Funding\Infrastructure\Models\FundingTransactionModel;
use Illuminate\Support\Facades\Queue;

final class FundingInitiationTest extends FundingTestCase
{
    // ── FR-E08-001: Initiate funding ─────────────────────────────────────────

    public function test_customer_can_initiate_mobile_money_funding(): void
    {
        Queue::fake();
        $customer = $this->makeActorWithPermission('CUSTOMER', 'funding.initiate');

        $response = $this->withActorToken($customer)
            ->postJson('/api/v1/funding/transactions', [
                'wallet_id'      => $this->walletId,
                'gross_amount'   => 10000,
                'currency_code'  => 'BWP',
                'funding_method' => FundingMethod::MobileMoney->value,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', FundingStatus::Pending->value)
            ->assertJsonPath('data.gross_amount', 10000)
            ->assertJsonPath('data.currency_code', 'BWP')
            ->assertJsonPath('data.funding_method', FundingMethod::MobileMoney->value)
            ->assertJsonStructure(['data' => ['id', 'transaction_reference', 'fee_amount', 'net_amount']]);
    }

    public function test_transaction_persisted_in_pending_status(): void
    {
        Queue::fake();
        $customer = $this->makeActorWithPermission('CUSTOMER', 'funding.initiate');

        $response = $this->withActorToken($customer)
            ->postJson('/api/v1/funding/transactions', [
                'wallet_id'      => $this->walletId,
                'gross_amount'   => 5000,
                'currency_code'  => 'BWP',
                'funding_method' => FundingMethod::MobileMoney->value,
            ]);

        $response->assertStatus(201);
        $id = $response->json('data.id');

        $this->assertDatabaseHas('funding.funding_transactions', [
            'id'     => $id,
            'status' => FundingStatus::Pending->value,
        ]);
    }

    // ── Wallet ownership validation ──────────────────────────────────────────

    public function test_rejects_funding_for_wallet_not_owned_by_customer(): void
    {
        Queue::fake();
        $otherCustomer = $this->makeActorWithPermission('CUSTOMER', 'funding.initiate');

        $response = $this->withActorToken($otherCustomer)
            ->postJson('/api/v1/funding/transactions', [
                'wallet_id'      => $this->walletId,  // owned by $this->customerId, not otherCustomer
                'gross_amount'   => 5000,
                'currency_code'  => 'BWP',
                'funding_method' => FundingMethod::MobileMoney->value,
            ]);

        $response->assertStatus(422);
    }

    // ── Limit enforcement ────────────────────────────────────────────────────

    public function test_rejects_amount_below_minimum(): void
    {
        Queue::fake();
        $customer = $this->makeActorWithPermission('CUSTOMER', 'funding.initiate');

        $response = $this->withActorToken($customer)
            ->postJson('/api/v1/funding/transactions', [
                'wallet_id'      => $this->walletId,
                'gross_amount'   => 50,   // below 100 minimum
                'currency_code'  => 'BWP',
                'funding_method' => FundingMethod::MobileMoney->value,
            ]);

        $response->assertStatus(422);
    }

    // ── Audit ────────────────────────────────────────────────────────────────

    public function test_audit_event_recorded_on_initiation(): void
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

    // ── Confirmation flow ────────────────────────────────────────────────────

    public function test_customer_can_confirm_pending_transaction(): void
    {
        Queue::fake();
        $customer = $this->makeActorWithPermission('CUSTOMER', 'funding.initiate');

        $initResponse = $this->withActorToken($customer)
            ->postJson('/api/v1/funding/transactions', [
                'wallet_id'      => $this->walletId,
                'gross_amount'   => 10000,
                'currency_code'  => 'BWP',
                'funding_method' => FundingMethod::MobileMoney->value,
            ]);

        $txId    = $initResponse->json('data.id');
        $feeAmount = $initResponse->json('data.fee_amount');

        $confirmResponse = $this->withActorToken($customer)
            ->postJson("/api/v1/funding/transactions/{$txId}/confirm", [
                'transaction_pin'     => '1234',
                'accepted_fee_amount' => $feeAmount,
            ]);

        $confirmResponse->assertStatus(200)
            ->assertJsonPath('data.status', FundingStatus::Processing->value);
    }

    public function test_customer_can_cancel_pending_transaction(): void
    {
        Queue::fake();
        $customer = $this->makeActorWithPermission('CUSTOMER', 'funding.initiate');

        $initResponse = $this->withActorToken($customer)
            ->postJson('/api/v1/funding/transactions', [
                'wallet_id'      => $this->walletId,
                'gross_amount'   => 10000,
                'currency_code'  => 'BWP',
                'funding_method' => FundingMethod::MobileMoney->value,
            ]);

        $txId = $initResponse->json('data.id');

        $cancelResponse = $this->withActorToken($customer)
            ->postJson("/api/v1/funding/transactions/{$txId}/cancel");

        $cancelResponse->assertStatus(200)
            ->assertJsonPath('data.status', FundingStatus::Cancelled->value);
    }

    // ── GET endpoints ────────────────────────────────────────────────────────

    public function test_customer_can_list_own_transactions(): void
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

        $response = $this->withActorToken($customer)
            ->getJson('/api/v1/funding/transactions');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta' => ['total', 'page', 'per_page', 'pages']]);
    }

    public function test_customer_can_get_own_transaction(): void
    {
        Queue::fake();
        $customer = $this->makeActorWithPermission('CUSTOMER', 'funding.initiate');

        $initResponse = $this->withActorToken($customer)
            ->postJson('/api/v1/funding/transactions', [
                'wallet_id'      => $this->walletId,
                'gross_amount'   => 10000,
                'currency_code'  => 'BWP',
                'funding_method' => FundingMethod::MobileMoney->value,
            ])
            ->assertStatus(201);

        $txId = $initResponse->json('data.id');

        $this->withActorToken($customer)
            ->getJson("/api/v1/funding/transactions/{$txId}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $txId);
    }
}
