<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;

/**
 * Phase 10 Feature Tests â€” Admin Session Revocation (T106)
 * AC-019: admin can revoke any user's session; revocation immediately reflects in Redis + DB.
 */

/** Log in as a user and return session data. */
function adminTestLoginAs(string $mobile, string $actorType, string $deviceId): array
{
    $users    = app(UserRepositoryInterface::class);
    $credRepo = app(CredentialRepositoryInterface::class);
    $devRepo  = app(DeviceRepositoryInterface::class);

    $user = $users->create([
        'mobile_number' => $mobile,
        'status'        => 'active',
        'actor_type'    => $actorType,
    ]);

    $credRepo->create([
        'user_id'         => $user->id,
        'credential_type' => 'pin',
        'credential_hash' => Hash::make('123456'),
        'status'          => 'active',
    ]);

    $devRepo->create([
        'user_id'            => $user->id,
        'device_fingerprint' => hash('sha256', $deviceId),
        'status'             => 'trusted',
        'platform'           => 'android',
    ]);

    $resp = test()->postJson('/v1/auth/login', [
        'identifier'         => $mobile,
        'credential_type'    => 'pin',
        'credential'         => '123456',
        'device_fingerprint' => $deviceId,
    ]);

    $resp->assertStatus(200);

    return [
        'user'          => $user,
        'access_token'  => $resp->json('data.access_token'),
        'refresh_token' => $resp->json('data.refresh_token'),
        'session_id'    => $resp->json('data.session_id'),
    ];
}

beforeEach(function (): void {
    Redis::connection('identity')->flushdb();
});

describe('POST /v1/admin/sessions/{session_id}/revoke (AC-019)', function (): void {

    it('admin can revoke any user session and receives 200', function (): void {
        // Create a target customer session
        $customer = adminTestLoginAs('+26771200100', 'CUSTOMER', 'cust-dev-100');

        // Create an admin and log in
        $admin = adminTestLoginAs('+26771200101', 'ADMIN', 'admin-dev-101');

        $response = test()->postJson(
            "/v1/admin/sessions/{$customer['session_id']}/revoke",
            [],
            [
                'Authorization'    => 'Bearer ' . $admin['access_token'],
                'X-Correlation-ID' => 'admin-rev-1',
            ],
        );

        $response->assertStatus(200);
        expect($response->json('success'))->toBeTrue();
    });

    it('revoked session is rejected on next authenticated request (IDENTITY_022)', function (): void {
        $customer = adminTestLoginAs('+26771200110', 'CUSTOMER', 'cust-dev-110');
        $admin    = adminTestLoginAs('+26771200111', 'ADMIN', 'admin-dev-111');

        // Admin revokes customer session
        test()->postJson(
            "/v1/admin/sessions/{$customer['session_id']}/revoke",
            [],
            ['Authorization' => 'Bearer ' . $admin['access_token']],
        )->assertStatus(200);

        // Customer tries to use their (now revoked) access token
        $authResp = test()->getJson('/v1/auth/sessions', [
            'Authorization' => 'Bearer ' . $customer['access_token'],
        ]);

        $authResp->assertStatus(401);
    });

    it('session status in DB is revoked after admin revocation', function (): void {
        $customer = adminTestLoginAs('+26771200120', 'CUSTOMER', 'cust-dev-120');
        $admin    = adminTestLoginAs('+26771200121', 'ADMIN', 'admin-dev-121');

        test()->postJson(
            "/v1/admin/sessions/{$customer['session_id']}/revoke",
            [],
            ['Authorization' => 'Bearer ' . $admin['access_token']],
        )->assertStatus(200);

        $dbSession = DB::connection('identity')
            ->table('identity.sessions')
            ->where('id', $customer['session_id'])
            ->first();

        expect($dbSession)->not->toBeNull();
        expect($dbSession->status)->toBe('revoked');
    });

    it('audit event identity.session.revoked is recorded with admin actor', function (): void {
        $customer = adminTestLoginAs('+26771200130', 'CUSTOMER', 'cust-dev-130');
        $admin    = adminTestLoginAs('+26771200131', 'ADMIN', 'admin-dev-131');

        test()->postJson(
            "/v1/admin/sessions/{$customer['session_id']}/revoke",
            [],
            [
                'Authorization'    => 'Bearer ' . $admin['access_token'],
                'X-Correlation-ID' => 'admin-audit-1',
            ],
        )->assertStatus(200);

        $auditRecord = DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'identity.session.revoked')
            ->where('actor_id', $admin['user']->id)
            ->first();

        expect($auditRecord)->not->toBeNull();
        expect($auditRecord->outcome)->toBe('success');
        expect($auditRecord->resource_id)->toBe($customer['session_id']);
    });

    it('revocation of a non-existent session returns 404', function (): void {
        $admin = adminTestLoginAs('+26771200140', 'ADMIN', 'admin-dev-140');

        $response = test()->postJson(
            '/v1/admin/sessions/00000000-0000-0000-0000-000000000000/revoke',
            [],
            ['Authorization' => 'Bearer ' . $admin['access_token']],
        );

        $response->assertStatus(404);
        expect($response->json('error.code'))->toBe('IDENTITY_011');
    });

    it('non-admin (CUSTOMER) cannot access the admin revoke endpoint (403)', function (): void {
        $victim   = adminTestLoginAs('+26771200150', 'CUSTOMER', 'cust-dev-150');
        $attacker = adminTestLoginAs('+26771200151', 'CUSTOMER', 'cust-dev-151');

        $response = test()->postJson(
            "/v1/admin/sessions/{$victim['session_id']}/revoke",
            [],
            ['Authorization' => 'Bearer ' . $attacker['access_token']],
        );

        $response->assertStatus(403);
        expect($response->json('error.code'))->toBe('IDENTITY_013');
    });

    it('unauthenticated request returns 401', function (): void {
        $response = test()->postJson(
            '/v1/admin/sessions/00000000-0000-0000-0000-000000000000/revoke',
        );

        $response->assertStatus(401);
    });

    it('admin cannot revoke an already-revoked session', function (): void {
        $customer = adminTestLoginAs('+26771200160', 'CUSTOMER', 'cust-dev-160');
        $admin    = adminTestLoginAs('+26771200161', 'ADMIN', 'admin-dev-161');

        // First revocation succeeds
        test()->postJson(
            "/v1/admin/sessions/{$customer['session_id']}/revoke",
            [],
            ['Authorization' => 'Bearer ' . $admin['access_token']],
        )->assertStatus(200);

        // Second revocation attempt â†’ 404 (session no longer active)
        $second = test()->postJson(
            "/v1/admin/sessions/{$customer['session_id']}/revoke",
            [],
            ['Authorization' => 'Bearer ' . $admin['access_token']],
        );

        $second->assertStatus(404);
    });

})->group('identity', 'session', 'admin');

