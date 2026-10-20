<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\DTO;

final readonly class NotificationContent
{
    public function __construct(
        public string $renderedSubject,
        public string $renderedBody,
        public array  $supportedChannels,
    ) {}
}
