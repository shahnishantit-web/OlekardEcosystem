<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\OtpServiceContract;
use App\Modules\Profile\Infrastructure\Jobs\ExpiredContactChallengeCleanupJob;
use App\Modules\Profile\Infrastructure\Models\UserProfileModel;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Ramsey\Uuid\Uuid;

// ---------------------------------------------------------------------------
// T251 — ExpiredContactChallengeCleanupJob: stale pending fields cleared correctly
// ---------------------------------------------------------------------------

describe('ExpiredContactChallengeCleanupJob (T251)', function (): void {

    // -------------------------------------------------------------------------
    // Active challenge — pending fields must be preserved
    // -------------------------------------------------------------------------
    it('preserves pending_email and pending_mobile when challenges are not expired', function (): void {
        $userId      = (string) Uuid::uuid7();
        $emailChalId = (string) Uuid::uuid7();
        $mobileChalId = (string) Uuid::uuid7();

        $profiles = app(EloquentUserProfileRepository::class);

        $profiles->create([
            'user_id'        => $userId,
            'account_status' => 'active',
            'email'          => 'current@example.com',
            'mobile_number'  => '+447700900001',
        ]);

        $profiles->update($userId, [
            'pending_email'               => 'new@example.com',
            'pending_email_challenge_id'  => $emailChalId,
            'pending_mobile'              => '+447700900999',
            'pending_mobile_challenge_id' => $mobileChalId,
        ]);

        $otp = Mockery::mock(OtpServiceContract::class);
        $otp->shouldReceive('isExpired')->with($emailChalId)->andReturn(false);
        $otp->shouldReceive('isExpired')->with($mobileChalId)->andReturn(false);

        (new ExpiredContactChallengeCleanupJob())->handle($profiles, $otp);

        $profile = UserProfileModel::where('user_id', $userId)->first();
        expect($profile->pending_email)->toBe('new@example.com');
        expect($profile->pending_email_challenge_id)->toBe($emailChalId);
        expect($profile->pending_mobile)->toBe('+447700900999');
        expect($profile->pending_mobile_challenge_id)->toBe($mobileChalId);
    });

    // -------------------------------------------------------------------------
    // Expired / missing challenge — pending fields must be nullified
    // -------------------------------------------------------------------------
    it('nullifies pending_email and pending_mobile when challenges are expired or missing', function (): void {
        $userId       = (string) Uuid::uuid7();
        $emailChalId  = (string) Uuid::uuid7();
        $mobileChalId = (string) Uuid::uuid7();

        $profiles = app(EloquentUserProfileRepository::class);

        $profiles->create([
            'user_id'        => $userId,
            'account_status' => 'active',
            'email'          => 'current@example.com',
            'mobile_number'  => '+447700900001',
        ]);

        $profiles->update($userId, [
            'pending_email'               => 'new@example.com',
            'pending_email_challenge_id'  => $emailChalId,
            'pending_mobile'              => '+447700900999',
            'pending_mobile_challenge_id' => $mobileChalId,
        ]);

        $otp = Mockery::mock(OtpServiceContract::class);
        $otp->shouldReceive('isExpired')->with($emailChalId)->andReturn(true);
        $otp->shouldReceive('isExpired')->with($mobileChalId)->andReturn(true);

        (new ExpiredContactChallengeCleanupJob())->handle($profiles, $otp);

        $profile = UserProfileModel::where('user_id', $userId)->first();
        expect($profile->pending_email)->toBeNull();
        expect($profile->pending_email_challenge_id)->toBeNull();
        expect($profile->pending_mobile)->toBeNull();
        expect($profile->pending_mobile_challenge_id)->toBeNull();
    });

    // -------------------------------------------------------------------------
    // Double-run idempotency — second run on already-cleaned profile is a no-op
    // -------------------------------------------------------------------------
    it('double-run produces identical results with no errors (idempotent)', function (): void {
        $userId      = (string) Uuid::uuid7();
        $challengeId = (string) Uuid::uuid7();

        $profiles = app(EloquentUserProfileRepository::class);

        $profiles->create([
            'user_id'        => $userId,
            'account_status' => 'active',
            'email'          => 'current@example.com',
        ]);

        $profiles->update($userId, [
            'pending_email'              => 'new@example.com',
            'pending_email_challenge_id' => $challengeId,
        ]);

        $otp = Mockery::mock(OtpServiceContract::class);
        // Returns true on the first run; second run skips this profile (pending_email_challenge_id is null)
        $otp->shouldReceive('isExpired')->with($challengeId)->andReturn(true);

        $job = new ExpiredContactChallengeCleanupJob();
        $job->handle($profiles, $otp);
        $job->handle($profiles, $otp); // second run must not error or corrupt state

        $profile = UserProfileModel::where('user_id', $userId)->first();
        expect($profile->pending_email)->toBeNull();
        expect($profile->pending_email_challenge_id)->toBeNull();
    });

});
