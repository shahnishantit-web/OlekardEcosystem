<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

final class PaymentIntentNotFoundException extends \RuntimeException
{
    public function __construct(string $id)
    {
        parent::__construct("PaymentIntent [{$id}] not found.");
    }
}
