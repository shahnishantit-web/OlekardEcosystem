<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Stubs;

use App\Modules\Identity\Domain\Contracts\OtpDeliveryContract;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryResult;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/**
 * Non-production stub: delivers OTPs to the application log at DEBUG level only.
 * MUST NOT be used in production. Swap to a real OtpDeliveryContract implementation
 * once the OTP provider (auth.otp.provider) TBD is resolved.
 */
final class StubOtpDeliveryProvider implements OtpDeliveryContract
{
    public function deliver(
        string $destination,
        string $otp,
        string $channelType,
        string $correlationId,
    ): OtpDeliveryResult {
        // DEBUG level only — never INFO or above in any environment
        Log::debug('StubOtpDeliveryProvider: OTP delivery simulated', [
            'channel'        => $channelType,
            'correlation_id' => $correlationId,
            'otp'            => $otp, // permissible ONLY at DEBUG in dev/test; strip before going to prod
        ]);

        return new OtpDeliveryResult(
            delivered:         true,
            providerReference: 'stub-' . Uuid::uuid4()->toString(),
            failureReason:     null,
        );
    }
}
