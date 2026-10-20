<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\AdminPasswordResetMailContract;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Infrastructure\Models\AdminPasswordResetToken;
use Illuminate\Support\Facades\Hash;

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeAdminUser(UserRepositoryInterface $users, CredentialRepositoryInterface $creds, string $email): \App\Modules\Identity\Infrastructure\Models\User
{
    $user = $users->create([
        'email'      => $email,
        'status'     => 'active',
        'actor_type' => 'ADMIN',
    ]);

    $creds->create([
        'user_id'         => $user->id,
        'credential_type' => 'password',
        'credential_hash' => Hash::make('OldAdmin@1234'),
        'status'          => 'active',
    ]);

    return $user;
}

// ── Test setup ────────────────────────────────────────────────────────────────

beforeEach(function (): void {
    $this->sentEmails = [];

    $self = $this;
    $this->app->bind(AdminPasswordResetMailContract::class, function () use ($self) {
        return new class($self) implements AdminPasswordResetMailContract {
            public function __construct(private readonly object $test) {}

            public function send(string $email, string $resetToken, string $correlationId): void
            {
                $this->test->sentEmails[] = [
                    'email'          => $email,
                    'reset_token'    => $resetToken,
                    'correlation_id' => $correlationId,
                ];
            }
        };
    });
});

// ── Feature tests ─────────────────────────────────────────────────────────────

describe('POST /v1/admin/auth/forgot-password (US-P1-001-001)', function (): void {

    it('returns 202 and queues reset email for a registered admin email', function (): void {
        $users = app(UserRepositoryInterface::class);
        $creds = app(CredentialRepositoryInterface::class);
        makeAdminUser($users, $creds, 'admin@olekard.local');

        $response = $this->postJson('/v1/admin/auth/forgot-password', [
            'email' => 'admin@olekard.local',
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.message', fn ($v) => str_contains($v, 'password reset link'));

        expect($this->sentEmails)->toHaveCount(1);
        expect($this->sentEmails[0]['email'])->toBe('admin@olekard.local');
        expect($this->sentEmails[0]['reset_token'])->toHaveLength(64); // hex(32 bytes)
    });

    it('stores a hashed reset token in the database', function (): void {
        $users = app(UserRepositoryInterface::class);
        $creds = app(CredentialRepositoryInterface::class);
        $user  = makeAdminUser($users, $creds, 'admin2@olekard.local');

        $this->postJson('/v1/admin/auth/forgot-password', ['email' => 'admin2@olekard.local']);

        $rawToken  = $this->sentEmails[0]['reset_token'];
        $tokenHash = hash('sha256', $rawToken);

        $record = AdminPasswordResetToken::where('user_id', $user->id)->first();
        expect($record)->not->toBeNull();
        expect($record->token_hash)->toBe($tokenHash);
        expect($record->used_at)->toBeNull();
        expect($record->expires_at->isPast())->toBeFalse();
    });

    it('returns 202 silently for an unknown email (prevents user enumeration)', function (): void {
        $response = $this->postJson('/v1/admin/auth/forgot-password', [
            'email' => 'nobody@example.com',
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('success', true);
        expect($this->sentEmails)->toHaveCount(0);
    });

    it('returns 202 silently for a non-admin user email (prevents actor-type enumeration)', function (): void {
        $users = app(UserRepositoryInterface::class);
        $creds = app(CredentialRepositoryInterface::class);

        $customer = $users->create([
            'mobile_number' => '+26771000099',
            'email'         => 'customer@example.com',
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
        ]);
        $creds->create([
            'user_id'         => $customer->id,
            'credential_type' => 'pin',
            'credential_hash' => Hash::make('123456'),
            'status'          => 'active',
        ]);

        $response = $this->postJson('/v1/admin/auth/forgot-password', [
            'email' => 'customer@example.com',
        ]);

        $response->assertStatus(202);
        expect($this->sentEmails)->toHaveCount(0);
    });

    it('invalidates previous unused token when a new request is made', function (): void {
        $users = app(UserRepositoryInterface::class);
        $creds = app(CredentialRepositoryInterface::class);
        $user  = makeAdminUser($users, $creds, 'admin3@olekard.local');

        // First request
        $this->postJson('/v1/admin/auth/forgot-password', ['email' => 'admin3@olekard.local']);
        $firstRawToken = $this->sentEmails[0]['reset_token'];

        // Second request
        $this->postJson('/v1/admin/auth/forgot-password', ['email' => 'admin3@olekard.local']);
        $secondRawToken = $this->sentEmails[1]['reset_token'];

        $firstHash  = hash('sha256', $firstRawToken);
        $secondHash = hash('sha256', $secondRawToken);

        $first  = AdminPasswordResetToken::where('token_hash', $firstHash)->first();
        $second = AdminPasswordResetToken::where('token_hash', $secondHash)->first();

        expect($first->used_at)->not->toBeNull();  // invalidated
        expect($second->used_at)->toBeNull();       // still valid
    });

    it('returns 422 for missing email', function (): void {
        $response = $this->postJson('/v1/admin/auth/forgot-password', []);
        $response->assertStatus(422);
        expect($this->sentEmails)->toHaveCount(0);
    });

    it('returns 422 for invalid email format', function (): void {
        $response = $this->postJson('/v1/admin/auth/forgot-password', ['email' => 'not-an-email']);
        $response->assertStatus(422);
        expect($this->sentEmails)->toHaveCount(0);
    });

    it('includes X-Correlation-ID in response header', function (): void {
        $response = $this->postJson('/v1/admin/auth/forgot-password', [
            'email' => 'any@example.com',
        ], ['X-Correlation-ID' => 'test-cid-123']);

        $response->assertHeader('X-Correlation-ID', 'test-cid-123');
    });

    it('fires ADMIN_PASSWORD_RESET_REQUESTED audit record', function (): void {
        $users = app(UserRepositoryInterface::class);
        $creds = app(CredentialRepositoryInterface::class);
        makeAdminUser($users, $creds, 'admin4@olekard.local');

        $this->postJson('/v1/admin/auth/forgot-password', ['email' => 'admin4@olekard.local'])
            ->assertStatus(202);

        $auditRecord = \DB::connection('platform')
            ->table('platform.audit_log')
            ->where('action', 'admin.password_reset_requested')
            ->where('outcome', 'success')
            ->first();

        expect($auditRecord)->not->toBeNull();
    });
});
