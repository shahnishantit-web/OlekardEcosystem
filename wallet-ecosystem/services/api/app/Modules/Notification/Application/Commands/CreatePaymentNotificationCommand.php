<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Commands;

final readonly class CreatePaymentNotificationCommand
{
    public function __construct(
        public string  $notificationType,
        public string  $sourceEventId,
        public string  $payerActorId,
        public ?string $correlationId,
        public int     $amountMinor,
        public string  $currencyCode,
    ) {}
}
