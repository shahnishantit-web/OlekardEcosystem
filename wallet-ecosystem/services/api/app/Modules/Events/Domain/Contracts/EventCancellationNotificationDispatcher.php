<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\Contracts;

use App\Modules\Notification\Domain\DTO\CreateNotificationCommand;

interface EventCancellationNotificationDispatcher
{
    public function dispatch(CreateNotificationCommand $command): void;
}
