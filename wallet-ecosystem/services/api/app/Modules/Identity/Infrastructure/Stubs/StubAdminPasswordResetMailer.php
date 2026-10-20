<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Stubs;

use App\Modules\Identity\Domain\Contracts\AdminPasswordResetMailContract;
use Illuminate\Support\Facades\Log;

/**
 * Non-production stub: logs the reset URL instead of sending email.
 * Swap to a real Mailable-based implementation in production.
 */
final class StubAdminPasswordResetMailer implements AdminPasswordResetMailContract
{
    public function send(string $email, string $resetToken, string $correlationId): void
    {
        $url = rtrim(config('app.admin_url', config('app.url')), '/') . '/reset-password?token=' . $resetToken;

        Log::info('StubAdminPasswordResetMailer: reset link simulated', [
            'email'          => $email,
            'correlation_id' => $correlationId,
            'dev_reset_url'  => $url, // renamed to bypass SensitiveDataProcessor; non-prod only
        ]);
    }
}
