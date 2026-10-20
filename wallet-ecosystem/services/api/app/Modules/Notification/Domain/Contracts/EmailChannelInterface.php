<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\Contracts;

use App\Modules\Notification\Domain\DTO\EmailDeliveryResult;

interface EmailChannelInterface
{
    public function send(string $emailAddress, string $subject, string $body): EmailDeliveryResult;
}
