<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\AdminPasswordResetMailContract;
use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Infrastructure\Models\AdminPasswordResetToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeAdminWithToken(UserRepositoryInterface $users, CredentialRepositoryInterface $creds, string $email): array
{
    $user = $users->create([
        'email' => $email,
        'status' => 'active',
        'actor_type' => 'ADMIN',
    ]);

    $creds->create([
        'user_id' => $user->id,
        'credential_type' => 'password',
        'credential_hash' => Hash::make('OldAdmin@1234'),
        'status' => 'active',
    ]);

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);

    AdminPasswordResetToken::create([
        'id' => (string) Str::orderedUuid(),
        'user_id' => $user->id,
        'token_hash' => $tokenHash,
        'expires_at' => now()->addHour(),
    ]);

    return ['user' => $user, 'raw_token' => $rawToken];
}

// ── Test setup ────────────────────────────────────────────────────────────────

beforeEach(function (): void {
    // The production reset service writes Argon2id hashes. Match its algorithm in this fixture.
    config(['hashing.driver' => 'argon2id']);
    app('hash')->forgetDrivers();
    $this->app->bind(AdminPasswordResetMailContract::class, function () {
        return new class implements AdminPasswordResetMailContract
        {
            public function send(string $email, string $resetToken, string $correlationId): void {}
        };
    });
});

// ── Feature tests ─────────────────────────────────────────────────────────────

describe('POST /v1/admin/auth/reset-password (US-P1-001-002)', function (): void {

    it('returns 200 and resets password for a valid token', function (): void {
        $users = app(UserRepositoryInterface::class);
        $creds = app(CredentialRepositoryInterface::class);
        ['user' => $user, 'raw_token' => $rawToken] = makeAdminWithToken($users, $creds, 'reset1@olekard.local');

        $response = $this->postJson('/v1/admin/auth/reset-password', [
            'token' => $rawToken,
            'password' => 'NewAdmin@5678!',
            'password_confirmation' => 'NewAdmin@5678!',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.message', fn ($v) => str_contains($v, 'password has been reset'));
    });

    it('marks the token as used after a successful reset', function (): void {
        $users = app(UserRepositoryInterface::class);
        $creds = app(CredentialRepositoryInterface::class);
        ['user' => $user, 'raw_token' => $rawToken] = makeAdminWithToken($users, $creds, 'reset2@olekard.local');

        $this->postJson('/v1/admin/auth/reset-password', [
            'token' => $rawToken,
            'password' => 'NewAdmin@5678!',
            'password_confirmation' => 'NewAdmin@5678!',
        ])->assertStatus(200);

        $tokenHash = hash('sha256', $rawToken);
        $record = AdminPasswordResetToken::where('token_hash', $tokenHash)->first();

        expect($record->used_at)->not->toBeNull();
    });

    it('updates the credential hash to the new password', function (): void {
        $users = app(UserRepositoryInterface::class);
        $creds = app(CredentialRepositoryInterface::class);
        ['user' => $user, 'raw_token' => $rawToken] = makeAdminWithToken($users, $creds, 'reset3@olekard.local');

        $this->postJson('/v1/admin/auth/reset-password', [
            'token' => $rawToken,
            'password' => 'NewAdmin@5678!',
            'password_confirmation' => 'NewAdmin@5678!',
        ])->assertStatus(200);

        $credential = $creds->findByUserAndType((string) $user->id, 'password');
        expect($credential)->not->toBeNull();
        expect(Hash::check('NewAdmin@5678!', $credential->credential_hash))->toBeTrue();
        expect(Hash::check('OldAdmin@1234', $credential->credential_hash))->toBeFalse();
    });

    it('returns 422 TOKEN_INVALID for an unknown token', function (): void {
        $fakeToken = str_repeat('a', 64);

        $response = $this->postJson('/v1/admin/auth/reset-password', [
            'token' => $fakeToken,
            'password' => 'NewAdmin@5678!',
            'password_confirmation' => 'NewAdmin@5678!',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'TOKEN_INVALID');
    });

    it('returns 422 TOKEN_EXPIRED for an expired token', function (): void {
        $users = app(UserRepositoryInterface::class);
        $creds = app(CredentialRepositoryInterface::class);

        $user = $users->create([
            'email' => 'reset4@olekard.local',
            'status' => 'active',
            'actor_type' => 'ADMIN',
        ]);
        $creds->create([
            'user_id' => $user->id,
            'credential_type' => 'password',
            'credential_hash' => Hash::make('OldAdmin@1234'),
            'status' => 'active',
        ]);

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        AdminPasswordResetToken::create([
            'id' => (string) Str::orderedUuid(),
            'user_id' => $user->id,
            'token_hash' => $tokenHash,
            'expires_at' => now()->subMinutes(5), // already expired
        ]);

        $response = $this->postJson('/v1/admin/auth/reset-password', [
            'token' => $rawToken,
            'password' => 'NewAdmin@5678!',
            'password_confirmation' => 'NewAdmin@5678!',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'TOKEN_EXPIRED');
    });

    it('returns 422 TOKEN_INVALID when the token has already been used', function (): void {
        $users = app(UserRepositoryInterface::class);
        $creds = app(CredentialRepositoryInterface::class);
        ['raw_token' => $rawToken] = makeAdminWithToken($users, $creds, 'reset5@olekard.local');

        $payload = [
            'token' => $rawToken,
            'password' => 'NewAdmin@5678!',
            'password_confirmation' => 'NewAdmin@5678!',
        ];

        // First request succeeds
        $this->postJson('/v1/admin/auth/reset-password', $payload)->assertStatus(200);

        // Second request with same token is rejected
        $response = $this->postJson('/v1/admin/auth/reset-password', $payload);
        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'TOKEN_INVALID');
    });

    it('returns 422 VALIDATION_ERROR for missing token', function (): void {
        $response = $this->postJson('/v1/admin/auth/reset-password', [
            'password' => 'NewAdmin@5678!',
            'password_confirmation' => 'NewAdmin@5678!',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
    });

    it('returns 422 VALIDATION_ERROR when password does not meet complexity rules', function (): void {
        $weakPasswords = ['short1A!', 'nouppercase1!', 'NONUMBER!!', 'NoSpecialChar1'];

        foreach ($weakPasswords as $weak) {
            $response = $this->postJson('/v1/admin/auth/reset-password', [
                'token' => str_repeat('b', 64),
                'password' => $weak,
                'password_confirmation' => $weak,
            ]);

            $response->assertStatus(422);
        }
    });

    it('returns 422 VALIDATION_ERROR when passwords do not match', function (): void {
        $response = $this->postJson('/v1/admin/auth/reset-password', [
            'token' => str_repeat('c', 64),
            'password' => 'NewAdmin@5678!',
            'password_confirmation' => 'DifferentPass@9!',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
    });

    it('includes X-Correlation-ID in response header', function (): void {
        $response = $this->postJson('/v1/admin/auth/reset-password', [
            'token' => str_repeat('d', 64),
            'password' => 'NewAdmin@5678!',
            'password_confirmation' => 'NewAdmin@5678!',
        ], ['X-Correlation-ID' => 'test-cid-reset-456']);

        $response->assertHeader('X-Correlation-ID', 'test-cid-reset-456');
    });

    it('fires ADMIN_PASSWORD_RESET_SUCCEEDED audit record', function (): void {
        $users = app(UserRepositoryInterface::class);
        $creds = app(CredentialRepositoryInterface::class);
        ['raw_token' => $rawToken] = makeAdminWithToken($users, $creds, 'reset6@olekard.local');

        $this->postJson('/v1/admin/auth/reset-password', [
            'token' => $rawToken,
            'password' => 'NewAdmin@5678!',
            'password_confirmation' => 'NewAdmin@5678!',
        ])->assertStatus(200);

        $auditRecord = DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'admin.password_reset_succeeded')
            ->where('outcome', 'success')
            ->first();

        expect($auditRecord)->not->toBeNull();
    });
});
