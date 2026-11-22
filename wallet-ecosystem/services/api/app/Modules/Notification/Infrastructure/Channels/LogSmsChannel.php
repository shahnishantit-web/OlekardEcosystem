<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Channels;

use App\Modules\Notification\Domain\Contracts\SmsChannelInterface;
use App\Modules\Notification\Domain\DTO\SmsDeliveryResult;
use Illuminate\Support\Facades\Log;

final class LogSmsChannel implements SmsChannelInterface
{
    public function send(string $phoneNumber, string $body): SmsDeliveryResult
    {
        $safeBody = preg_replace('/([?&](?:token|invite|code)=)[^&\\s]+/i', '$1[REDACTED]', $body) ?? '[REDACTED]';
        Log::info('notification.sms.log', [
            'channel' => 'sms',
            'phone'   => substr($phoneNumber, 0, 4).'****',
            'body'    => $safeBody,
        ]);

        return new SmsDeliveryResult(success: true);
    }
}
