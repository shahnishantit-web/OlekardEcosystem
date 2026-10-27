<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class PaymentHistoryApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->paymentSchemaAccessible()) {
            $this->markTestSkipped('Docker-gated: requires running PostgreSQL payment schema.');
        }
    }

    public function test_consumer_cannot_see_other_payers_records(): void
    {
        $payerA = (string) Uuid::uuid7();
        $payerB = (string) Uuid::uuid7();
        $orgId  = (string) Uuid::uuid7();

        $this->seedPaymentIntent($payerA, $orgId);

        $this->actingAsUser($payerB, 'CUSTOMER', ['payment.history.read']);

        $response = $this->getJson('/api/v1/payment/history');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 0);
    }

    public function test_consumer_sees_own_records(): void
    {
        $payerA = (string) Uuid::uuid7();
        $orgId  = (string) Uuid::uuid7();

        $this->seedPaymentIntent($payerA, $orgId);

        $this->actingAsUser($payerA, 'CUSTOMER', ['payment.history.read']);

        $response = $this->getJson('/api/v1/payment/history');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);
    }

    public function test_admin_history_requires_permission(): void
    {
        $this->actingAsUser((string) Uuid::uuid7(), 'ADMIN', []);

        $response = $this->getJson('/api/v1/admin/payment/history');

        $response->assertStatus(403);
    }

    public function test_admin_can_filter_by_organisation(): void
    {
        $orgId  = (string) Uuid::uuid7();
        $payer  = (string) Uuid::uuid7();
        $this->seedPaymentIntent($payer, $orgId);

        $adminId = (string) Uuid::uuid7();
        $this->actingAsUser($adminId, 'ADMIN', ['payment.history.read.organisation']);

        $response = $this->getJson('/api/v1/admin/payment/history?organisation_id=' . $orgId);

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);
    }

    public function test_platform_admin_can_query_cross_organisation(): void
    {
        $orgA  = (string) Uuid::uuid7();
        $orgB  = (string) Uuid::uuid7();
        $this->seedPaymentIntent((string) Uuid::uuid7(), $orgA);
        $this->seedPaymentIntent((string) Uuid::uuid7(), $orgB);

        $this->actingAsUser((string) Uuid::uuid7(), 'ADMIN', ['payment.history.read.platform']);

        $response = $this->getJson('/api/v1/admin/payment/history');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(2, $response->json('meta.total'));
    }

    public function test_pagination_defaults_to_20_per_page(): void
    {
        $payer = (string) Uuid::uuid7();
        $orgId = (string) Uuid::uuid7();

        $this->actingAsUser($payer, 'CUSTOMER', ['payment.history.read']);

        $response = $this->getJson('/api/v1/payment/history');

        $response->assertOk();
        $response->assertJsonPath('meta.per_page', 20);
    }

    public function test_purpose_type_filter_returns_correct_subset(): void
    {
        $payer = (string) Uuid::uuid7();
        $orgId = (string) Uuid::uuid7();

        $this->seedPaymentIntent($payer, $orgId, purposeType: 'funeral_assistance');
        $this->seedPaymentIntent($payer, $orgId, purposeType: 'wallet_transfer');

        $this->actingAsUser($payer, 'CUSTOMER', ['payment.history.read']);

        $response = $this->getJson('/api/v1/payment/history?purpose_type=funeral_assistance');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);
        $this->assertSame('funeral_assistance', $response->json('data.0.purposeType'));
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

    private function seedPaymentIntent(string $payerActorId, string $orgId, string $purposeType = 'wallet_transfer'): string
    {
        $id = (string) Uuid::uuid7();

        DB::connection('payment')->table('payment.payment_intents')->insert([
            'id'                => $id,
            'reference'         => 'REF-' . substr($id, 0, 8),
            'purpose_type'      => $purposeType,
            'purpose_reference' => 'ref-' . substr($id, 0, 8),
            'organisation_id'   => $orgId,
            'payer_actor_id'    => $payerActorId,
            'currency_code'     => 'BWP',
            'amount_minor'      => 10000,
            'status'            => 'succeeded',
            'version'           => 1,
            'idempotency_key'   => 'idem-' . $id,
            'correlation_id'    => 'corr-' . $id,
            'metadata'          => '{}',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        return $id;
    }
}
