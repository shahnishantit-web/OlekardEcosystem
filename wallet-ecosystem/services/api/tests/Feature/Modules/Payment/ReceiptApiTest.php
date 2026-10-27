<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class ReceiptApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->paymentSchemaAccessible()) {
            $this->markTestSkipped('Docker-gated: requires running PostgreSQL payment schema.');
        }
    }

    public function test_non_payer_cannot_access_receipt_returns_404(): void
    {
        $payerA  = (string) Uuid::uuid7();
        $payerB  = (string) Uuid::uuid7();
        $orgId   = (string) Uuid::uuid7();
        $intentId = $this->seedConfirmedPaymentWithAttempt($payerA, $orgId);

        $this->actingAsUser($payerB, 'CUSTOMER', ['payment.history.read']);

        $response = $this->getJson("/api/v1/payment/receipts/{$intentId}");

        $response->assertNotFound();
    }

    public function test_receipt_returns_confirmed_payment_data(): void
    {
        $payer   = (string) Uuid::uuid7();
        $orgId   = (string) Uuid::uuid7();
        $intentId = $this->seedConfirmedPaymentWithAttempt($payer, $orgId, feeMinor: 300);

        $this->actingAsUser($payer, 'CUSTOMER', ['payment.history.read']);

        $response = $this->getJson("/api/v1/payment/receipts/{$intentId}");

        $response->assertOk();
        $response->assertJsonPath('data.receiptStatus', 'confirmed');
        $response->assertJsonPath('data.feeAmountMinor', 300);
    }

    public function test_receipt_shows_pending_refund_as_pending(): void
    {
        $payer    = (string) Uuid::uuid7();
        $orgId    = (string) Uuid::uuid7();
        $intentId = $this->seedConfirmedPaymentWithAttempt($payer, $orgId);
        $this->seedRefund($intentId, 'requested');

        $this->actingAsUser($payer, 'CUSTOMER', ['payment.history.read']);

        $response = $this->getJson("/api/v1/payment/receipts/{$intentId}");

        $response->assertOk();
        $refundSummary = $response->json('data.refundSummary');
        $this->assertNotEmpty($refundSummary);
        $this->assertSame('requested', $refundSummary[0]['status']);
    }

    public function test_download_token_is_single_use(): void
    {
        $payer    = (string) Uuid::uuid7();
        $orgId    = (string) Uuid::uuid7();
        $intentId = $this->seedConfirmedPaymentWithAttempt($payer, $orgId);

        $this->actingAsUser($payer, 'CUSTOMER', ['payment.history.read']);

        $tokenResponse = $this->postJson("/api/v1/payment/receipts/{$intentId}/download-token");
        $tokenResponse->assertCreated();

        $token = $tokenResponse->json('data.token');

        // First download — should succeed
        $first = $this->getJson("/api/v1/payment/receipts/download/{$token}");
        $first->assertOk();

        // Second download — should return 410
        $second = $this->getJson("/api/v1/payment/receipts/download/{$token}");
        $second->assertStatus(410);
    }

    public function test_expired_download_token_returns_410(): void
    {
        $payer    = (string) Uuid::uuid7();
        $orgId    = (string) Uuid::uuid7();
        $intentId = $this->seedConfirmedPaymentWithAttempt($payer, $orgId);
        $token    = $this->seedExpiredDownloadToken($intentId, $payer);

        $response = $this->getJson("/api/v1/payment/receipts/download/{$token}");
        $response->assertStatus(410);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function paymentSchemaAccessible(): bool
    {
        try {
            DB::connection('payment')->statement('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function seedConfirmedPaymentWithAttempt(
        string $payerActorId,
        string $orgId,
        int    $feeMinor = 100,
    ): string {
        $intentId  = (string) Uuid::uuid7();
        $attemptId = (string) Uuid::uuid7();

        DB::connection('payment')->table('payment.payment_intents')->insert([
            'id'                => $intentId,
            'reference'         => 'REF-' . substr($intentId, 0, 8),
            'purpose_type'      => 'wallet_transfer',
            'purpose_reference' => 'ref-' . substr($intentId, 0, 8),
            'organisation_id'   => $orgId,
            'payer_actor_id'    => $payerActorId,
            'currency_code'     => 'BWP',
            'amount_minor'      => 10000,
            'status'            => 'succeeded',
            'version'           => 1,
            'idempotency_key'   => 'idem-' . $intentId,
            'correlation_id'    => 'corr-' . $intentId,
            'metadata'          => '{}',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        DB::connection('payment')->table('payment.payment_attempts')->insert([
            'id'                  => $attemptId,
            'payment_intent_id'   => $intentId,
            'provider_code'       => 'test_bank',
            'provider_account_id' => 'acct-001',
            'status'              => 'succeeded',
            'idempotency_key'     => 'attempt-idem-' . $intentId,
            'operation_id'        => 'op-' . $intentId,
            'fee_minor'           => $feeMinor,
            'confirmed_at'        => now(),
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        return $intentId;
    }

    private function seedRefund(string $intentId, string $status): void
    {
        DB::connection('payment')->table('payment.payment_refunds')->insert([
            'id'                => (string) Uuid::uuid7(),
            'payment_intent_id' => $intentId,
            'amount_minor'      => 5000,
            'currency_code'     => 'BWP',
            'status'            => $status,
            'requestor_id'      => 'user-test',
            'idempotency_key'   => 'refund-idem-' . $intentId,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    private function seedExpiredDownloadToken(string $intentId, string $requestorId): string
    {
        $token = bin2hex(random_bytes(32));

        DB::connection('payment')->table('payment.receipt_download_tokens')->insert([
            'id'                => (string) Uuid::uuid7(),
            'payment_intent_id' => $intentId,
            'requestor_id'      => $requestorId,
            'token'             => $token,
            'expires_at'        => now()->subMinute(),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        return $token;
    }
}
