<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

use App\Modules\Identity\Domain\Exceptions\OtpDeliveryException;

interface OtpDeliveryContract
{
    /**
     * @param string $destination  E.164 mobile or email address
     * @param string $otp          Plaintext OTP — provider delivers it; MUST NOT be logged or stored
     * @param string $channelType  'sms' | 'email'
     * @param string $correlationId
     * @throws OtpDeliveryException on provider failure
     */
    public function deliver(
        string $destination,
        string $otp,
        string $channelType,
        string $correlationId,
    ): OtpDeliveryResult;
}
