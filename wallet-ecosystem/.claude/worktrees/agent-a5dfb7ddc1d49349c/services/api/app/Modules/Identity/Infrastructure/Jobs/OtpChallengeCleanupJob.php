<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Jobs;

use App\Modules\Identity\Infrastructure\Models\OtpChallenge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Purges OTP challenges with terminal statuses (expired, invalidated, verified) older than 24 hours.
 * Pending challenges are NEVER purged.
 */
final class OtpChallengeCleanupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $cutoff = now()->subHours(24);

        $deleted = OtpChallenge::whereIn('status', ['expired', 'invalidated', 'verified'])
            ->where('updated_at', '<', $cutoff)
            ->delete();

        Log::info('identity.otp_challenge.cleanup.completed', [
            'deleted' => $deleted,
            'cutoff'  => $cutoff->toIso8601String(),
        ]);
    }
}
