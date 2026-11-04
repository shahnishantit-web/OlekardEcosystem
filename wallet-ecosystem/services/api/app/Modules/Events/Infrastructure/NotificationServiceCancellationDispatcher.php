<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure;

use App\Modules\Events\Domain\Contracts\EventCancellationNotificationDispatcher;
use App\Modules\Notification\Application\Services\NotificationService;
use App\Modules\Notification\Domain\DTO\CreateNotificationCommand;

final class NotificationServiceCancellationDispatcher implements EventCancellationNotificationDispatcher
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function dispatch(CreateNotificationCommand $command): void
    {
        $this->notifications->createFromEvent($command);
    }
}
