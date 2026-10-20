<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\DTO;

use App\Modules\Notification\Domain\Enums\NotificationCategory;

final readonly class CreateNotificationCommand
{
    public function __construct(
        public string               $sourceEventId,
        public string               $sourceEventType,
        public string               $notificationType,
        public NotificationCategory $category,
        public bool                 $mandatory,
        public string               $recipientId,
        public array                $variables,
        public string               $correlationId,
    ) {}
}
