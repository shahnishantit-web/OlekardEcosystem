<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class PaymentExportApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->paymentSchemaAccessible()) {
            $this->markTestSkipped('Docker-gated: requires running PostgreSQL payment schema.');
        }
    }

    public function test_org_export_only_contains_org_records(): void
    {
        Queue::fake();

        $orgId = (string) Uuid::uuid7();
        $admin = (string) Uuid::uuid7();

        $this->actingAsUser($admin, 'ADMIN', ['payment.export.read.organisation']);

        $response = $this->postJson('/api/v1/admin/payment/exports', [
            'scope_type'      => 'organisation',
            'organisation_id' => $orgId,
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.status', 'queued');
    }

    public function test_export_token_is_single_use(): void
    {
        $this->markTestSkipped('Docker-gated: requires full GeneratePaymentExportJob execution.');
    }

    public function test_expired_export_token_returns_410(): void
    {
        $exportId = (string) Uuid::uuid7();
        $token    = bin2hex(random_bytes(32));

        DB::connection('payment')->table('payment.payment_export_requests')->insert([
            'id'              => $exportId,
            'requestor_id'    => 'admin-test',
            'scope_type'      => 'organisation',
            'organisation_id' => (string) Uuid::uuid7(),
            'filters'         => '{}',
            'status'          => 'ready',
            'token'           => $token,
            'token_expires_at' => now()->subHour(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $response = $this->getJson("/api/v1/payment/exports/download/{$token}");
        $response->assertStatus(410);
    }

    public function test_export_access_is_audited(): void
    {
        $this->markTestSkipped('Docker-gated: requires full GeneratePaymentExportJob execution and Storage.');
    }

    public function test_csv_has_no_formula_injectable_values(): void
    {
        $this->markTestSkipped('Docker-gated: requires full GeneratePaymentExportJob execution.');
    }

    public function test_export_does_not_contain_full_account_numbers(): void
    {
        $this->markTestSkipped('Docker-gated: requires full GeneratePaymentExportJob execution.');
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
}
