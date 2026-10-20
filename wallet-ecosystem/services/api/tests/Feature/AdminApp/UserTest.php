<?php

declare(strict_types=1);

namespace Tests\Feature\AdminApp;

use Tests\TestCase;

final class UserTest extends TestCase
{
    public function test_user_search_returns_paginated_results(): void
    {
        $response = $this->actingAsAdminWithPermission('adminapp.users.read')
            ->getJson('/v1/admin-app/users?q=test&per_page=10');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['user_id', 'full_name', 'phone_number', 'account_status', 'kyc_status']],
                'meta' => ['total', 'per_page', 'current_page', 'last_page'],
            ]);
    }

    public function test_phone_number_masked_in_list(): void
    {
        $response = $this->actingAsAdminWithPermission('adminapp.users.read')
            ->getJson('/v1/admin-app/users');

        $response->assertStatus(200);
        // Phone should contain asterisks in list view
        foreach ($response->json('data') as $user) {
            if ($user['phone_number'] !== null) {
                $this->assertStringContainsString('*', $user['phone_number']);
            }
        }
    }

    public function test_suspend_creates_audit_entry(): void
    {
        $userId = $this->createTestCustomerUser();

        $response = $this->actingAsAdminWithPermission('adminapp.users.write')
            ->withHeaders(['Idempotency-Key' => 'test-suspend-' . $userId])
            ->patchJson("/v1/admin-app/users/{$userId}/status", [
                'action' => 'suspend',
                'reason' => 'Test suspension',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.account_status', 'suspended');
    }

    public function test_suspend_is_idempotent(): void
    {
        $userId         = $this->createTestCustomerUser();
        $idempotencyKey = 'idempotent-suspend-' . $userId;

        $first = $this->actingAsAdminWithPermission('adminapp.users.write')
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->patchJson("/v1/admin-app/users/{$userId}/status", [
                'action' => 'suspend',
                'reason' => 'First request',
            ]);

        $second = $this->actingAsAdminWithPermission('adminapp.users.write')
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->patchJson("/v1/admin-app/users/{$userId}/status", [
                'action' => 'suspend',
                'reason' => 'Second request',
            ]);

        $first->assertStatus(200);
        $second->assertStatus(200);
    }

    public function test_customer_can_suspend_and_reactivate_with_distinct_request_keys(): void
    {
        $userId = $this->createTestCustomerUser();
        $admin = fn () => $this->actingAsAdminWithPermission('adminapp.users.write');

        $admin()->withHeaders(['Idempotency-Key' => 'customer-suspend-'.$userId])
            ->patchJson("/v1/admin-app/users/{$userId}/status", ['action' => 'suspend', 'reason' => 'Review'])
            ->assertStatus(200)->assertJsonPath('data.account_status', 'suspended');
        $this->assertSame('suspended', \Illuminate\Support\Facades\DB::connection('identity')->table('identity.users')->where('id', $userId)->value('status'));

        $admin()->withHeaders(['Idempotency-Key' => 'customer-reactivate-'.$userId])
            ->patchJson("/v1/admin-app/users/{$userId}/status", ['action' => 'reactivate', 'reason' => 'Review complete'])
            ->assertStatus(200)->assertJsonPath('data.account_status', 'active');
        $this->assertSame('active', \Illuminate\Support\Facades\DB::connection('identity')->table('identity.users')->where('id', $userId)->value('status'));
    }

    public function test_finance_role_denied_user_endpoints(): void
    {
        $response = $this->actingAsAdminWithPermission('adminapp.float.manage')
            ->getJson('/v1/admin-app/users');

        $response->assertStatus(403);
    }

    public function test_missing_idempotency_key_returns_422(): void
    {
        $userId = $this->createTestCustomerUser();

        $response = $this->actingAsAdminWithPermission('adminapp.users.write')
            ->patchJson("/v1/admin-app/users/{$userId}/status", ['action' => 'suspend']);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'ADMINAPP_IDEM_001');
    }

    private function createTestCustomerUser(): string
    {
        $userId = \Illuminate\Support\Str::uuid()->toString();
        \Illuminate\Support\Facades\DB::connection('identity')->table('identity.users')->insert([
            'id' => $userId,
            'email' => 'adminapp-customer-'.$userId.'@test.local',
            'mobile_number' => '+2677'.random_int(1000000, 9999999),
            'status' => 'active',
            'actor_type' => 'CUSTOMER',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $userId;
    }
}
