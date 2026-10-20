<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Ledger;

use Ramsey\Uuid\Uuid;
use Tests\Support\LedgerTestCase;

/**
 * T052 — US-LEDGER-008: HTTP immutability enforcement.
 * Covers: no PATCH/PUT/DELETE endpoints for ledger entries or journals → 405 Method Not Allowed.
 */
final class LedgerImmutabilityHttpTest extends LedgerTestCase
{
    // Scenario (a): no HTTP PATCH/PUT/DELETE endpoint exists for /v1/ledger/entries/* → 405.
    public function test_patch_on_entries_returns_405(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');

        $response = $this->withActorToken($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->patchJson('/v1/ledger/entries/' . (string) Uuid::uuid7(), []);

        $response->assertStatus(405);
    }

    public function test_put_on_entries_returns_405(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');

        $response = $this->withActorToken($admin)
            ->putJson('/v1/ledger/entries/' . (string) Uuid::uuid7(), []);

        $response->assertStatus(405);
    }

    public function test_delete_on_entries_returns_405(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.post');

        $response = $this->withActorToken($admin)
            ->deleteJson('/v1/ledger/entries/' . (string) Uuid::uuid7());

        $response->assertStatus(405);
    }

    // Scenario (b): no HTTP PATCH/DELETE endpoint for /v1/ledger/journals/* → 405.
    public function test_patch_on_journals_returns_405(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.read');

        $response = $this->withActorToken($admin)
            ->patchJson('/v1/ledger/journals/' . (string) Uuid::uuid7(), []);

        $response->assertStatus(405);
    }

    public function test_delete_on_journals_returns_405(): void
    {
        $admin = $this->makeAdminWithPermission('ledger.read');

        $response = $this->withActorToken($admin)
            ->deleteJson('/v1/ledger/journals/' . (string) Uuid::uuid7());

        $response->assertStatus(405);
    }
}
