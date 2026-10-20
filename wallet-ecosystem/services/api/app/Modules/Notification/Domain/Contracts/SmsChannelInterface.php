<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\Contracts;

use App\Modules\Notification\Domain\DTO\SmsDeliveryResult;

interface SmsChannelInterface
{
    public function send(string $phoneNumber, string $body): SmsDeliveryResult;
}
