<?php

declare(strict_types=1);

namespace App\Modules\Profile\Infrastructure\Jobs;

use App\Modules\Identity\Domain\Contracts\OtpServiceContract;
use App\Modules\Profile\Infrastructure\Models\UserProfileModel;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Hourly cleanup job: nullifies pending_email / pending_mobile contact-change fields
 * on profiles whose OTP challenge has expired.
 *
 * E01 expiry is checked via OtpServiceContract::isExpired() — not by reading
 * the OtpChallenge Eloquent model directly — to preserve module extraction-readiness
 * per Constitution P7.
 */
final class ExpiredContactChallengeCleanupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(EloquentUserProfileRepository $profiles, OtpServiceContract $otp): void
    {
        UserProfileModel::whereNotNull('pending_email_challenge_id')
            ->cursor()
            ->each(static function (UserProfileModel $profile) use ($profiles, $otp): void {
                if ($otp->isExpired($profile->pending_email_challenge_id)) {
                    $profiles->update($profile->user_id, [
                        'pending_email'              => null,
                        'pending_email_challenge_id' => null,
                    ]);
                }
            });

        UserProfileModel::whereNotNull('pending_mobile_challenge_id')
            ->cursor()
            ->each(static function (UserProfileModel $profile) use ($profiles, $otp): void {
                if ($otp->isExpired($profile->pending_mobile_challenge_id)) {
                    $profiles->update($profile->user_id, [
                        'pending_mobile'              => null,
                        'pending_mobile_challenge_id' => null,
                    ]);
                }
            });
    }
}
