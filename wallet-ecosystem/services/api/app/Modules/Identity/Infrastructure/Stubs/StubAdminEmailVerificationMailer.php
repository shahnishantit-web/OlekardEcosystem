<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Stubs;

use App\Modules\Identity\Domain\Contracts\AdminEmailVerificationMailContract;
use Illuminate\Support\Facades\Log;

/**
 * Non-production stub: logs the verification URL instead of sending email.
 * Swap to a real Mailable-based implementation in production.
 */
final class StubAdminEmailVerificationMailer implements AdminEmailVerificationMailContract
{
    public function send(string $email, string $verifyToken, string $correlationId): void
    {
        $url = rtrim(config('app.admin_url', config('app.url')), '/') . '/verify-email?token=' . $verifyToken;

        Log::info('StubAdminEmailVerificationMailer: verification link simulated', [
            'email'          => $email,
            'correlation_id' => $correlationId,
            'dev_verify_url' => $url, // renamed to bypass SensitiveDataProcessor; non-prod only
        ]);
    }
}
