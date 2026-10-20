<?php

declare(strict_types=1);

namespace Tests\Feature\AdminApp;

use Tests\TestCase;

final class ReconciliationTest extends TestCase
{
    public function test_list_reconciliation_batches(): void
    {
        $response = $this->actingAsAdminWithPermission('adminapp.reconciliation.read')
            ->getJson('/v1/admin-app/reconciliation');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_show_reconciliation_batch_includes_unmatched_ids(): void
    {
        $batchId = $this->createReconciliationBatch();

        $response = $this->actingAsAdminWithPermission('adminapp.reconciliation.read')
            ->getJson("/v1/admin-app/reconciliation/{$batchId}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['batch_id', 'status', 'unmatched_transaction_ids'],
            ]);
    }

    public function test_list_settlements(): void
    {
        $response = $this->actingAsAdminWithPermission('adminapp.settlement.read')
            ->getJson('/v1/admin-app/settlement');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_operations_role_denied_reconciliation(): void
    {
        $response = $this->actingAsAdminWithPermission('adminapp.users.read')
            ->getJson('/v1/admin-app/reconciliation');

        $response->assertStatus(403);
    }

    private function createReconciliationBatch(): string
    {
        return \Illuminate\Support\Str::uuid()->toString();
    }
}
