<?php

declare(strict_types=1);

namespace Tests\E2E\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * @group docker
 * @group e2e
 *
 * Scenario 5 (quickstart.md Scenario 2 extension): Refresh token rotation attack.
 * Establish session → rotate refresh token → present OLD token → all family sessions revoked →
 * Identity.Session.AllTerminated event emitted.
 */
final class RefreshTokenReuseAttackTest extends TestCase
{
    use RefreshDatabase;

    public function test_scenario_5_refresh_token_reuse_revokes_all_family_sessions(): void
    {
        $mobile = '+254' . mt_rand(700000000, 799999999);
        $userId = $this->seedActiveUser($mobile);

        // Step 1: Initial login → get access + refresh token
        $login = $this->postJson('/v1/auth/login', [
            'mobile_number' => $mobile,
            'pin'           => '111111',
        ]);
        $login->assertOk();
        $originalRefreshToken = $login->json('data.refresh_token');
        $this->assertNotEmpty($originalRefreshToken);

        // Step 2: Rotate refresh token (normal flow → new pair issued)
        $rotate = $this->postJson('/v1/auth/token/refresh', [
            'refresh_token' => $originalRefreshToken,
        ]);
        $rotate->assertOk();
        $newRefreshToken = $rotate->json('data.refresh_token');
        $this->assertNotEmpty($newRefreshToken);
        $this->assertNotSame($originalRefreshToken, $newRefreshToken);

        // Step 3: Re-use the OLD refresh token → attack detected → all sessions revoked
        $reuse = $this->postJson('/v1/auth/token/refresh', [
            'refresh_token' => $originalRefreshToken,
        ]);
        $reuse->assertStatus(401);

        // Step 4: Verify Identity.Session.AllTerminated event emitted
        $event = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Identity.Session.AllTerminated')
            ->where('payload', 'like', "%{$userId}%")
            ->first();
        $this->assertNotNull($event, 'Identity.Session.AllTerminated event must be emitted on token reuse');

        // Step 5: New refresh token also no longer works (sessions all revoked)
        $reuseNew = $this->postJson('/v1/auth/token/refresh', [
            'refresh_token' => $newRefreshToken,
        ]);
        $reuseNew->assertStatus(401);
    }

    private function seedActiveUser(string $mobile): string
    {
        $userId = (string) \Ramsey\Uuid\Uuid::uuid7();
        DB::connection('identity')->table('identity.users')->insert([
            'id'            => $userId,
            'mobile_number' => $mobile,
            'email'         => "e2e-reuse-{$userId}@test.local",
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        DB::connection('identity')->table('identity.user_pins')->insert([
            'id'         => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'user_id'    => $userId,
            'pin_hash'   => bcrypt('111111'),
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $userId;
    }
}
