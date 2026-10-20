<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\AdminEmailVerificationMailContract;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Infrastructure\Models\AdminEmailVerificationToken;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use Illuminate\Support\Str;

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeUnverifiedAdmin(UserRepositoryInterface $users, string $email): \App\Modules\Identity\Infrastructure\Models\User
{
    return $users->create([
        'email'      => $email,
        'status'     => 'pending_verification',
        'actor_type' => 'ADMIN',
    ]);
}

function makeVerificationToken(\App\Modules\Identity\Infrastructure\Models\User $user, int $ttlHours = 24, bool $used = false): array
{
    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);

    AdminEmailVerificationToken::create([
        'id'         => (string) Str::orderedUuid(),
        'user_id'    => $user->id,
        'token_hash' => $tokenHash,
        'expires_at' => now()->addHours($ttlHours),
        'used_at'    => $used ? now() : null,
    ]);

    return ['raw' => $rawToken, 'hash' => $tokenHash];
}

// ── Test setup ────────────────────────────────────────────────────────────────

beforeEach(function (): void {
    $this->sentEmails = [];

    $self = $this;
    $this->app->bind(AdminEmailVerificationMailContract::class, function () use ($self) {
        return new class($self) implements AdminEmailVerificationMailContract {
            public function __construct(private readonly object $test) {}

            public function send(string $email, string $verifyToken, string $correlationId): void
            {
                $this->test->sentEmails[] = [
                    'email'          => $email,
                    'verify_token'   => $verifyToken,
                    'correlation_id' => $correlationId,
                ];
            }
        };
    });
});

// ── Verify email endpoint tests ───────────────────────────────────────────────

describe('POST /v1/admin/auth/verify-email (US-P1-001-005)', function (): void {

    it('returns 200 and activates admin account for a valid token', function (): void {
        $users = app(UserRepositoryInterface::class);
        $user  = makeUnverifiedAdmin($users, 'newadmin@olekard.local');
        $token = makeVerificationToken($user);

        $response = $this->postJson('/v1/admin/auth/verify-email', [
            'token' => $token['raw'],
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $user->refresh();
        expect($user->getAttribute('status'))->toBe('active');
    });

    it('marks the token as used after successful verification', function (): void {
        $users = app(UserRepositoryInterface::class);
        $user  = makeUnverifiedAdmin($users, 'newadmin2@olekard.local');
        $token = makeVerificationToken($user);

        $this->postJson('/v1/admin/auth/verify-email', ['token' => $token['raw']]);

        $record = AdminEmailVerificationToken::where('token_hash', $token['hash'])->first();
        expect($record->used_at)->not()->toBeNull();
    });

    it('returns 422 TOKEN_INVALID for an unknown token', function (): void {
        $unknownToken = bin2hex(random_bytes(32));

        $response = $this->postJson('/v1/admin/auth/verify-email', [
            'token' => $unknownToken,
        ]);

        $response->assertStatus(422)
            ->assertJson(['error' => ['code' => 'TOKEN_INVALID']]);
    });

    it('returns 422 TOKEN_EXPIRED for an expired token', function (): void {
        $users = app(UserRepositoryInterface::class);
        $user  = makeUnverifiedAdmin($users, 'newadmin3@olekard.local');
        $token = makeVerificationToken($user, ttlHours: -1);

        $response = $this->postJson('/v1/admin/auth/verify-email', [
            'token' => $token['raw'],
        ]);

        $response->assertStatus(422)
            ->assertJson(['error' => ['code' => 'TOKEN_EXPIRED']]);
    });

    it('returns 422 TOKEN_INVALID for an already-used token (replay prevention)', function (): void {
        $users = app(UserRepositoryInterface::class);
        $user  = makeUnverifiedAdmin($users, 'newadmin4@olekard.local');
        $token = makeVerificationToken($user, used: true);

        $response = $this->postJson('/v1/admin/auth/verify-email', [
            'token' => $token['raw'],
        ]);

        $response->assertStatus(422)
            ->assertJson(['error' => ['code' => 'TOKEN_INVALID']]);
    });

    it('returns 422 VALIDATION_ERROR for a token that is not 64 characters', function (): void {
        $response = $this->postJson('/v1/admin/auth/verify-email', [
            'token' => 'short-token',
        ]);

        $response->assertStatus(422);
    });

    it('returns 422 VALIDATION_ERROR when token is missing', function (): void {
        $response = $this->postJson('/v1/admin/auth/verify-email', []);

        $response->assertStatus(422);
    });

    it('includes X-Correlation-ID in response header', function (): void {
        $users = app(UserRepositoryInterface::class);
        $user  = makeUnverifiedAdmin($users, 'newadmin5@olekard.local');
        $token = makeVerificationToken($user);

        $response = $this->postJson('/v1/admin/auth/verify-email', ['token' => $token['raw']], [
            'X-Correlation-ID' => 'test-corr-id-001',
        ]);

        $response->assertHeader('X-Correlation-ID', 'test-corr-id-001');
    });

    it('fires admin.email_verified audit record on success', function (): void {
        $users  = app(UserRepositoryInterface::class);
        $audit  = \Mockery::mock(AuditServiceInterface::class);
        $this->app->instance(AuditServiceInterface::class, $audit);

        $user   = makeUnverifiedAdmin($users, 'newadmin6@olekard.local');
        $token  = makeVerificationToken($user);

        $audit->shouldReceive('record')
            ->once()
            ->withArgs(fn ($data) => $data->action === 'admin.email_verified');

        $this->postJson('/v1/admin/auth/verify-email', ['token' => $token['raw']]);
    });

});

// ── Resend verification endpoint tests ───────────────────────────────────────

describe('POST /v1/admin/auth/resend-verification (US-P1-001-005)', function (): void {

    it('returns 202 and sends a new verification email for an unverified admin', function (): void {
        $users = app(UserRepositoryInterface::class);
        makeUnverifiedAdmin($users, 'pending@olekard.local');

        $response = $this->postJson('/v1/admin/auth/resend-verification', [
            'email' => 'pending@olekard.local',
        ]);

        $response->assertStatus(202)
            ->assertJson(['success' => true]);

        expect($this->sentEmails)->toHaveCount(1);
        expect($this->sentEmails[0]['email'])->toBe('pending@olekard.local');
    });

    it('invalidates old tokens before issuing a new one', function (): void {
        $users = app(UserRepositoryInterface::class);
        $user  = makeUnverifiedAdmin($users, 'pending2@olekard.local');
        makeVerificationToken($user);

        $this->postJson('/v1/admin/auth/resend-verification', [
            'email' => 'pending2@olekard.local',
        ]);

        $active = AdminEmailVerificationToken::where('user_id', $user->id)
            ->whereNull('used_at')
            ->count();
        expect($active)->toBe(1);
    });

    it('returns 202 silently for an unknown email (no enumeration)', function (): void {
        $response = $this->postJson('/v1/admin/auth/resend-verification', [
            'email' => 'nobody@olekard.local',
        ]);

        $response->assertStatus(202)
            ->assertJson(['success' => true]);

        expect($this->sentEmails)->toHaveCount(0);
    });

    it('returns 202 silently for an already-active admin (no enumeration)', function (): void {
        $users = app(UserRepositoryInterface::class);
        $users->create([
            'email'      => 'active@olekard.local',
            'status'     => 'active',
            'actor_type' => 'ADMIN',
        ]);

        $response = $this->postJson('/v1/admin/auth/resend-verification', [
            'email' => 'active@olekard.local',
        ]);

        $response->assertStatus(202);
        expect($this->sentEmails)->toHaveCount(0);
    });

    it('returns 202 silently for a non-admin user (no enumeration)', function (): void {
        $users = app(UserRepositoryInterface::class);
        $users->create([
            'email'      => 'customer@example.com',
            'status'     => 'pending_verification',
            'actor_type' => 'CUSTOMER',
        ]);

        $response = $this->postJson('/v1/admin/auth/resend-verification', [
            'email' => 'customer@example.com',
        ]);

        $response->assertStatus(202);
        expect($this->sentEmails)->toHaveCount(0);
    });

    it('returns 422 VALIDATION_ERROR for an invalid email format', function (): void {
        $response = $this->postJson('/v1/admin/auth/resend-verification', [
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422);
    });

    it('includes X-Correlation-ID in response header', function (): void {
        $response = $this->postJson('/v1/admin/auth/resend-verification', [
            'email' => 'nobody2@olekard.local',
        ], ['X-Correlation-ID' => 'test-corr-id-002']);

        $response->assertHeader('X-Correlation-ID', 'test-corr-id-002');
    });

});
