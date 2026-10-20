<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Channels;

use App\Modules\Notification\Domain\Contracts\PushChannelInterface;
use App\Modules\Notification\Domain\DTO\PushDeliveryResult;
use Illuminate\Support\Facades\Log;

final class LogPushChannel implements PushChannelInterface
{
    public function send(string $deviceTokenValue, string $platform, string $subject, string $body, array $metadata = []): PushDeliveryResult
    {
        Log::info('notification.push.log', [
            'channel'    => 'push',
            'platform'   => $platform,
            'token'      => substr($deviceTokenValue, 0, 8).'...',
            'subject'    => $subject,
            'body'       => $body,
            'metadata'   => $metadata,
        ]);

        return new PushDeliveryResult(success: true, tokenInvalid: false);
    }
}
