<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\NullImplementations;

use App\Exceptions\ModuleNotImplementedException;
use App\Modules\Notification\Domain\Contracts\NotificationModuleContract;

final class NullNotificationModule implements NotificationModuleContract
{
    public function dispatch(string $recipientId, string $channel, array $payload): void
    {
        throw new ModuleNotImplementedException('Notification', 'dispatch');
    }
}
