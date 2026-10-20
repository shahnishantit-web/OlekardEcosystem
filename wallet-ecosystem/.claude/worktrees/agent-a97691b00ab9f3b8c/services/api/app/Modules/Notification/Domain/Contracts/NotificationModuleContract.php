<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\Contracts;

interface NotificationModuleContract
{
    public function dispatch(string $recipientId, string $channel, array $payload): void;
}
