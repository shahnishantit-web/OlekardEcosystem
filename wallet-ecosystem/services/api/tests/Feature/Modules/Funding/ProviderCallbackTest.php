<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Funding;

use App\Modules\Funding\Domain\Enums\FundingMethod;
use App\Modules\Funding\Domain\Enums\FundingStatus;
use App\Modules\Funding\Infrastructure\Models\FundingTransactionModel;
use App\Modules\Funding\Infrastructure\Models\FundingReceiptModel;
use Illuminate\Support\Facades\Queue;

final class ProviderCallbackTest extends FundingTestCase
{
    private function buildValidCallbackPayload(string $txRef, string $providerRef, string $status = 'success'): array
    {
        return [
            'transaction_reference'   => $txRef,
            'provider_transaction_id' => $providerRef,
            'status'                  => $status,
            'amount'                  => 10000,
            'currency'                => 'BWP',
        ];
    }

    private function computeHmacSignature(array $payload): string
    {
        // Default mock HMAC secret (from MockFundingProviderAdapter)
        return hash_hmac('sha256', json_encode($payload), 'mock-secret-change-in-production');
    }

    private function createProcessingTransaction(): FundingTransactionModel
    {
        Queue::fake();
        $tx                        = new FundingTransactionModel();
        $tx->id                    = (string) \Ramsey\Uuid\Uuid::uuid7();
        $tx->customer_id           = $this->customerId;
        $tx->wallet_id             = $this->walletId;
        $tx->pocket_currency_code  = 'BWP';
        $tx->funding_method        = FundingMethod::MobileMoney;
        $tx->status                = FundingStatus::Processing;
        $tx->gross_amount          = 10000;
        $tx->fee_amount            = 150;
        $tx->net_amount            = 9850;
        $tx->transaction_reference = 'FND-TEST-' . uniqid();
        $tx->provider_reference_id = 'MOCK-' . $tx->id;
        $tx->correlation_id        = (string) \Ramsey\Uuid\Uuid::uuid7();
        $tx->save();

        return $tx;
    }

    // ── FR-E08-027 — FR-E08-035: Callback processing ─────────────────────────

    public function test_valid_success_callback_completes_transaction(): void
    {
        $tx      = $this->createProcessingTransaction();
        $payload = $this->buildValidCallbackPayload($tx->transaction_reference, $tx->provider_reference_id);
        $sig     = $this->computeHmacSignature($payload);

        $response = $this->postJson('/api/v1/funding/callbacks/mock', $payload, [
            'X-Signature' => $sig,
        ]);

        $response->assertStatus(200)->assertJson(['acknowledged' => true]);

        $this->assertDatabaseHas('funding.funding_transactions', [
            'id'     => $tx->id,
            'status' => FundingStatus::Completed->value,
        ]);
    }

    public function test_success_callback_creates_funding_receipt(): void
    {
        $tx      = $this->createProcessingTransaction();
        $payload = $this->buildValidCallbackPayload($tx->transaction_reference, $tx->provider_reference_id);
        $sig     = $this->computeHmacSignature($payload);

        $this->postJson('/api/v1/funding/callbacks/mock', $payload, ['X-Signature' => $sig])
            ->assertStatus(200);

        $this->assertDatabaseHas('funding.funding_receipts', [
            'funding_transaction_id' => $tx->id,
        ]);
    }

    public function test_invalid_signature_returns_400(): void
    {
        $tx      = $this->createProcessingTransaction();
        $payload = $this->buildValidCallbackPayload($tx->transaction_reference, $tx->provider_reference_id);

        $response = $this->postJson('/api/v1/funding/callbacks/mock', $payload, [
            'X-Signature' => 'invalid-signature',
        ]);

        // SR-E08-001: invalid signature MUST return 400 INVALID_CALLBACK_SIGNATURE
        $response->assertStatus(400);

        // No state change on invalid signature
        $this->assertDatabaseHas('funding.funding_transactions', [
            'id'     => $tx->id,
            'status' => FundingStatus::Processing->value,
        ]);
    }

    // ── EC-E08-02: Late callback on already-failed transaction ────────────────

    public function test_success_callback_on_failed_transaction_emits_late_callback_event(): void
    {
        // Create a transaction that is already in failed status
        $tx                 = $this->createProcessingTransaction();
        $tx->status         = FundingStatus::Failed;
        $tx->failure_reason = \App\Modules\Funding\Domain\Enums\FundingFailureReason::ProcessingTimeout;
        $tx->save();

        $payload = $this->buildValidCallbackPayload($tx->transaction_reference, $tx->provider_reference_id);
        $sig     = $this->computeHmacSignature($payload);

        // Should return 200 (idempotent) — not an error
        $this->postJson('/api/v1/funding/callbacks/mock', $payload, ['X-Signature' => $sig])
            ->assertStatus(200);

        // Transaction must remain failed — no re-credit
        $this->assertDatabaseHas('funding.funding_transactions', [
            'id'     => $tx->id,
            'status' => FundingStatus::Failed->value,
        ]);

        // No receipt should have been created
        $this->assertDatabaseMissing('funding.funding_receipts', [
            'funding_transaction_id' => $tx->id,
        ]);

        // No ledger journal should have been posted
        $this->assertDatabaseMissing('ledger.journal_entries', [
            'idempotency_key' => 'funding_callback:' . $tx->provider_reference_id,
        ]);
    }

    public function test_failure_callback_marks_transaction_failed(): void
    {
        $tx      = $this->createProcessingTransaction();
        $payload = $this->buildValidCallbackPayload($tx->transaction_reference, $tx->provider_reference_id, 'failure');
        $sig     = $this->computeHmacSignature($payload);

        $this->postJson('/api/v1/funding/callbacks/mock', $payload, ['X-Signature' => $sig])
            ->assertStatus(200);

        $this->assertDatabaseHas('funding.funding_transactions', [
            'id'     => $tx->id,
            'status' => FundingStatus::Failed->value,
        ]);
    }

    public function test_duplicate_callback_returns_200(): void
    {
        $tx      = $this->createProcessingTransaction();
        $payload = $this->buildValidCallbackPayload($tx->transaction_reference, $tx->provider_reference_id);
        $sig     = $this->computeHmacSignature($payload);

        // First call
        $this->postJson('/api/v1/funding/callbacks/mock', $payload, ['X-Signature' => $sig])
            ->assertStatus(200);

        // Second call (duplicate)
        $this->postJson('/api/v1/funding/callbacks/mock', $payload, ['X-Signature' => $sig])
            ->assertStatus(200)->assertJson(['acknowledged' => true]);
    }

    // ── Receipt endpoint ─────────────────────────────────────────────────────

    public function test_customer_can_get_receipt_after_completion(): void
    {
        $tx      = $this->createProcessingTransaction();
        $payload = $this->buildValidCallbackPayload($tx->transaction_reference, $tx->provider_reference_id);
        $sig     = $this->computeHmacSignature($payload);

        $this->postJson('/api/v1/funding/callbacks/mock', $payload, ['X-Signature' => $sig])
            ->assertStatus(200);

        $customer = (object) ['id' => $this->customerId, 'actor_type' => 'CUSTOMER'];
        $response = $this->withActorToken($customer)
            ->getJson("/api/v1/funding/transactions/{$tx->id}/receipt");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['id', 'receipt_reference', 'amount', 'currency_code', 'issued_at']]);
    }
}
