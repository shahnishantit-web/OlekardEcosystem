<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Services\ConfigurationSecurityService;
use App\Modules\Identity\Infrastructure\Jobs\OtpChallengeCleanupJob;
use App\Modules\Identity\Infrastructure\Jobs\SessionCleanupJob;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Identity\Infrastructure\Models\OtpChallenge;
use App\Modules\Identity\Infrastructure\Models\Session;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Phase 8 Retention Tests — T095
 * SessionCleanupJob and OtpChallengeCleanupJob retention behaviour.
 */

beforeEach(function (): void {
    Redis::connection('identity')->flushdb();
});

function makeUser(): User
{
    return User::create([
        'mobile_number' => '+2677' . rand(10000000, 99999999),
        'status'        => 'active',
        'actor_type'    => 'CUSTOMER',
    ]);
}

/** Create a session with backdated timestamps using forceCreate + withoutTimestamps. */
function makeSession(User $user, string $status, int $daysAgo): string
{
    $sessionId = (string) Str::uuid();
    $cutoff    = now()->subDays($daysAgo);

    Session::withoutTimestamps(static fn () => Session::forceCreate([
        'id'                      => $sessionId,
        'user_id'                 => $user->id,
        'device_id'               => null,
        'refresh_token_hash'      => hash('sha256', $sessionId),
        'refresh_token_family_id' => (string) Str::uuid(),
        'status'                  => $status,
        'actor_type'              => 'CUSTOMER',
        'last_activity_at'        => $cutoff,
        'expires_at'              => $cutoff,
        'ip_hash'                 => hash('sha256', '1.2.3.4'),
        'created_at'              => $cutoff,
        'updated_at'              => $cutoff,
    ]));

    return $sessionId;
}

/** Create an OTP challenge with backdated timestamps. */
function makeChallenge(User $user, string $status, int $hoursAgo): string
{
    $challengeId = (string) Str::uuid();
    $cutoff      = now()->subHours($hoursAgo);

    OtpChallenge::withoutTimestamps(static fn () => OtpChallenge::forceCreate([
        'id'             => $challengeId,
        'user_id'        => $user->id,
        'challenge_type' => 'registration',
        'identifier'     => '+2677' . rand(10000000, 99999999),
        'otp_hash'       => hash('sha256', '000000'),
        'status'         => $status,
        'attempt_count'  => 0,
        'resend_count'   => 0,
        'expires_at'     => $cutoff,
        'created_at'     => $cutoff,
        'updated_at'     => $cutoff,
    ]));

    return $challengeId;
}

describe('SessionCleanupJob (T087)', function (): void {

    it('purges revoked sessions older than retention window', function (): void {
        $user      = makeUser();
        $sessionId = makeSession($user, 'revoked', 91);

        expect(Session::where('id', $sessionId)->exists())->toBeTrue();

        app(SessionCleanupJob::class)->handle(app(ConfigurationSecurityService::class), app(EventPublisherInterface::class));

        expect(Session::where('id', $sessionId)->exists())->toBeFalse();
    });

    it('purges logged_out sessions older than retention window', function (): void {
        $user      = makeUser();
        $sessionId = makeSession($user, 'logged_out', 92);

        app(SessionCleanupJob::class)->handle(app(ConfigurationSecurityService::class), app(EventPublisherInterface::class));

        expect(Session::where('id', $sessionId)->exists())->toBeFalse();
    });

    it('does NOT purge active sessions regardless of age', function (): void {
        $user      = makeUser();
        $sessionId = (string) Str::uuid();

        // Active session: created 95 days ago but still active
        Session::withoutTimestamps(static fn () => Session::forceCreate([
            'id'                      => $sessionId,
            'user_id'                 => $user->id,
            'device_id'               => null,
            'refresh_token_hash'      => hash('sha256', $sessionId . '-active'),
            'refresh_token_family_id' => (string) Str::uuid(),
            'status'                  => 'active',
            'actor_type'              => 'CUSTOMER',
            'last_activity_at'        => now()->subDays(5),
            'expires_at'              => now()->addDays(25),
            'ip_hash'                 => hash('sha256', '1.2.3.4'),
            'created_at'              => now()->subDays(95),
            'updated_at'              => now()->subDays(95),
        ]));

        app(SessionCleanupJob::class)->handle(app(ConfigurationSecurityService::class), app(EventPublisherInterface::class));

        expect(Session::where('id', $sessionId)->exists())->toBeTrue();
    });

    it('does NOT purge terminal sessions within the retention window', function (): void {
        $user      = makeUser();
        $sessionId = makeSession($user, 'logged_out', 1); // only 1 day old

        app(SessionCleanupJob::class)->handle(app(ConfigurationSecurityService::class), app(EventPublisherInterface::class));

        expect(Session::where('id', $sessionId)->exists())->toBeTrue();
    });

});

describe('OtpChallengeCleanupJob (T088)', function (): void {

    it('purges expired OTP challenges older than 24 hours', function (): void {
        $user        = makeUser();
        $challengeId = makeChallenge($user, 'expired', 25);

        expect(OtpChallenge::where('id', $challengeId)->exists())->toBeTrue();

        app(OtpChallengeCleanupJob::class)->handle();

        expect(OtpChallenge::where('id', $challengeId)->exists())->toBeFalse();
    });

    it('purges verified OTP challenges older than 24 hours', function (): void {
        $user        = makeUser();
        $challengeId = makeChallenge($user, 'verified', 26);

        app(OtpChallengeCleanupJob::class)->handle();

        expect(OtpChallenge::where('id', $challengeId)->exists())->toBeFalse();
    });

    it('does NOT purge pending OTP challenges', function (): void {
        $user        = makeUser();
        $challengeId = makeChallenge($user, 'pending', 30);

        app(OtpChallengeCleanupJob::class)->handle();

        expect(OtpChallenge::where('id', $challengeId)->exists())->toBeTrue();
    });

    it('does NOT purge expired challenges within the 24h window', function (): void {
        $user        = makeUser();
        $challengeId = makeChallenge($user, 'verified', 1); // only 1 hour old

        app(OtpChallengeCleanupJob::class)->handle();

        expect(OtpChallenge::where('id', $challengeId)->exists())->toBeTrue();
    });

});
