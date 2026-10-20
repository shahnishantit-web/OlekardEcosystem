<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\Contracts;

use App\Modules\Notification\Domain\DTO\PushDeliveryResult;

interface PushChannelInterface
{
    public function send(string $deviceTokenValue, string $platform, string $subject, string $body, array $metadata = []): PushDeliveryResult;
}
