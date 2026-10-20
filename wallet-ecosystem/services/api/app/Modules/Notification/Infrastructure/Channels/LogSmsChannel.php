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
        Log::info('notification.sms.log', [
            'channel' => 'sms',
            'phone'   => substr($phoneNumber, 0, 4).'****',
            'body'    => $body,
        ]);

        return new SmsDeliveryResult(success: true);
    }
}
