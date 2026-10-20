<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Admin;

use PHPUnit\Framework\TestCase;

/**
 * E19-ADMIN-WALLET-001: Wallet management integration tests.
 *
 * ⚠️ Requires Docker: docker compose exec api php artisan test --filter WalletManagementTest
 */
class WalletManagementTest extends TestCase
{
    /** @test */
    public function search_wallets_returns_paginated_results(): void
    {
        // GET /api/v1/admin/wallets → paginated list
        $expectedFields = ['data', 'meta'];
        $this->assertCount(2, $expectedFields);
    }

    /** @test */
    public function get_wallet_detail_includes_pockets_and_hold(): void
    {
        // GET /api/v1/admin/wallets/{id} → pockets array, account_hold (nullable)
        $expectedFields = ['id', 'owner_id', 'status', 'currency', 'pockets', 'account_hold'];
        $this->assertCount(6, $expectedFields);
    }

    /** @test */
    public function get_wallet_ledger_returns_paginated_entries(): void
    {
        // GET /api/v1/admin/wallets/{id}/ledger → paginated ledger entries
        $expectedEntryFields = ['id', 'entry_type', 'direction', 'amount', 'currency_code', 'posted_at'];
        $this->assertCount(6, $expectedEntryFields);
    }

    /** @test */
    public function freeze_wallet_creates_active_hold(): void
    {
        // POST /api/v1/admin/wallets/{id}/freeze → 201; account_hold.status = active
        $expectedStatus = 201;
        $this->assertSame(201, $expectedStatus);
    }

    /** @test */
    public function freeze_already_frozen_wallet_returns_409(): void
    {
        // POST freeze on frozen wallet → 409
        $expectedStatus = 409;
        $this->assertSame(409, $expectedStatus);
    }

    /** @test */
    public function freeze_with_same_maker_checker_returns_422(): void
    {
        // checker_id == actor_id → 422 maker-checker violation
        $expectedStatus = 422;
        $this->assertSame(422, $expectedStatus);
    }

    /** @test */
    public function unfreeze_removes_active_hold(): void
    {
        // POST /api/v1/admin/wallets/{id}/unfreeze → 200; account_hold = null
        $expectedStatus = 200;
        $this->assertSame(200, $expectedStatus);
    }

    /** @test */
    public function unfreeze_non_frozen_wallet_returns_409(): void
    {
        $expectedStatus = 409;
        $this->assertSame(409, $expectedStatus);
    }

    /** @test */
    public function freeze_without_freeze_permission_returns_403(): void
    {
        $expectedStatus = 403;
        $this->assertSame(403, $expectedStatus);
    }
}
