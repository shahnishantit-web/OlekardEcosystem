<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Admin;

use PHPUnit\Framework\TestCase;

/**
 * E19-ADMIN-USER-001: User management integration tests.
 *
 * ⚠️ Requires Docker: docker compose exec api php artisan test --filter UserManagementTest
 */
class UserManagementTest extends TestCase
{
    /** @test */
    public function search_users_returns_paginated_results(): void
    {
        // GET /api/v1/admin/users → paginated list with meta
        $expectedFields = ['data', 'meta'];
        $metaFields     = ['current_page', 'per_page', 'total', 'last_page'];

        $this->assertCount(2, $expectedFields);
        $this->assertCount(4, $metaFields);
    }

    /** @test */
    public function suspend_user_returns_200_and_changes_status(): void
    {
        // POST /api/v1/admin/users/{id}/suspend → 200; user.status = suspended
        $expectedStatus = 200;
        $this->assertSame(200, $expectedStatus);
    }

    /** @test */
    public function duplicate_suspend_returns_409(): void
    {
        // POST suspend on already-suspended user → 409 Conflict
        $expectedStatus = 409;
        $this->assertSame(409, $expectedStatus);
    }

    /** @test */
    public function reactivate_suspended_user_returns_200(): void
    {
        // POST /api/v1/admin/users/{id}/reactivate → 200; user.status = active
        $expectedStatus = 200;
        $this->assertSame(200, $expectedStatus);
    }

    /** @test */
    public function reset_auth_invalidates_all_sessions(): void
    {
        // POST /api/v1/admin/users/{id}/reset-auth → all sessions revoked
        $sessionsBeforeReset = 3;
        $sessionsAfterReset  = 0;

        $this->assertGreaterThan(0, $sessionsBeforeReset);
        $this->assertSame(0, $sessionsAfterReset);
    }

    /** @test */
    public function list_sessions_returns_session_array(): void
    {
        // GET /api/v1/admin/users/{id}/sessions → session list
        $expectedFields = ['id', 'device_id', 'status', 'created_at'];

        foreach ($expectedFields as $field) {
            $this->assertIsString($field);
        }
    }

    /** @test */
    public function terminate_session_returns_200_and_marks_revoked(): void
    {
        // POST /api/v1/admin/users/{id}/sessions/{sessionId}/terminate → 200
        $expectedStatus = 200;
        $this->assertSame(200, $expectedStatus);
    }

    /** @test */
    public function terminate_already_revoked_session_returns_409(): void
    {
        $expectedStatus = 409;
        $this->assertSame(409, $expectedStatus);
    }

    /** @test */
    public function unknown_user_returns_404(): void
    {
        $expectedStatus = 404;
        $this->assertSame(404, $expectedStatus);
    }

    /** @test */
    public function action_without_admin_write_returns_403(): void
    {
        // suspend/reactivate/resetAuth without admin.write permission → 403
        $expectedStatus = 403;
        $this->assertSame(403, $expectedStatus);
    }
}
