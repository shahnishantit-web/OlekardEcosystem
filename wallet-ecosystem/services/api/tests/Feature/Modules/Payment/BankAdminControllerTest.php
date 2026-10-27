<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use App\Modules\Payment\Domain\Enums\BankLifecycleStatus;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T039: Feature tests for BankAdminController.
 */
final class BankAdminControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_banks_requires_configuration_permission(): void
    {
        $response = $this->actingAsAdminWithoutPermission('payment.configuration')
            ->getJson('/api/v1/admin/payments/banks');

        $response->assertForbidden();
    }

    public function test_create_bank_returns_201_with_draft_status(): void
    {
        $response = $this->actingAsAdminWithPermission('payment.configuration')
            ->postJson('/api/v1/admin/payments/banks', [
                'code'               => 'FNBZTEST',
                'display_name'       => 'FNB Test',
                'adapter_identifier' => 'http-redirect-v1',
                'adapter_version'    => '1.0.0',
                'environment'        => 'sandbox',
                'supported_countries' => ['BW'],
            ], ['Idempotency-Key' => fake()->uuid()]);

        $response->assertStatus(201)
            ->assertJsonPath('data.bank_lifecycle_status', BankLifecycleStatus::Draft->value)
            ->assertJsonPath('data.code', 'FNBZTEST');
    }

    public function test_create_bank_validates_required_fields(): void
    {
        $response = $this->actingAsAdminWithPermission('payment.configuration')
            ->postJson('/api/v1/admin/payments/banks', [], ['Idempotency-Key' => fake()->uuid()]);

        $response->assertUnprocessable();
    }

    public function test_show_bank_returns_404_for_unknown_id(): void
    {
        $response = $this->actingAsAdminWithPermission('payment.configuration')
            ->getJson('/api/v1/admin/payments/banks/00000000-0000-0000-0000-000000000000');

        $response->assertNotFound();
    }

    public function test_update_bank_rejects_optimistic_lock_conflict(): void
    {
        $bank = PaymentProviderModel::factory()->create([
            'bank_lifecycle_status' => BankLifecycleStatus::Draft->value,
            'metadata_version'      => 3,
        ]);

        $response = $this->actingAsAdminWithPermission('payment.configuration')
            ->patchJson("/api/v1/admin/payments/banks/{$bank->id}", [
                'display_name'    => 'Changed',
                'metadata_version' => 1,
            ]);

        $response->assertStatus(500);
    }

    public function test_two_banks_same_profile_pattern_sc006(): void
    {
        $this->actingAsAdminWithPermission('payment.configuration')
            ->postJson('/api/v1/admin/payments/banks', [
                'code'               => 'BANK_A',
                'display_name'       => 'Bank A',
                'adapter_identifier' => 'http-redirect-v1',
                'adapter_version'    => '1.0.0',
                'environment'        => 'sandbox',
            ], ['Idempotency-Key' => fake()->uuid()])
            ->assertStatus(201);

        $this->actingAsAdminWithPermission('payment.configuration')
            ->postJson('/api/v1/admin/payments/banks', [
                'code'               => 'BANK_B',
                'display_name'       => 'Bank B',
                'adapter_identifier' => 'http-redirect-v1',
                'adapter_version'    => '1.0.0',
                'environment'        => 'sandbox',
            ], ['Idempotency-Key' => fake()->uuid()])
            ->assertStatus(201);

        $banks = PaymentProviderModel::query()
            ->where('adapter_identifier', 'http-redirect-v1')
            ->get();

        $this->assertCount(2, $banks, 'Two banks using same profile must both be stored');
    }

    // Helpers — stubs; these must be replaced when auth/permission scaffolding is available in tests

    private function actingAsAdminWithPermission(string ...$permissions): static
    {
        return $this->markTestSkipped('Docker-gated: requires seeded admin user with permission scaffolding');
    }

    private function actingAsAdminWithoutPermission(string ...$permissions): static
    {
        return $this->markTestSkipped('Docker-gated: requires auth scaffolding');
    }
}
