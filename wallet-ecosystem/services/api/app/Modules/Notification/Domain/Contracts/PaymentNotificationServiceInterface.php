<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\Contracts;

use App\Modules\Notification\Application\Commands\CreatePaymentNotificationCommand;

interface PaymentNotificationServiceInterface
{
    public function createFromPaymentEvent(CreatePaymentNotificationCommand $command): void;
}
