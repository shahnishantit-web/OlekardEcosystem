<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Channels;

use App\Modules\Notification\Domain\Contracts\EmailChannelInterface;
use App\Modules\Notification\Domain\DTO\EmailDeliveryResult;
use Illuminate\Support\Facades\Log;

final class LogEmailChannel implements EmailChannelInterface
{
    public function send(string $emailAddress, string $subject, string $body): EmailDeliveryResult
    {
        Log::info('notification.email.log', [
            'channel' => 'email',
            'email'   => $emailAddress,
            'subject' => $subject,
            'body'    => $body,
        ]);

        return new EmailDeliveryResult(success: true);
    }
}
