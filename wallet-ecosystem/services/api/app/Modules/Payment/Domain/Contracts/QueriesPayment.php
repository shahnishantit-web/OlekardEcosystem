<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Domain\ValueObjects\PaymentStatusResult;

interface QueriesPayment
{
    public function queryPaymentStatus(string $providerReferenceId): PaymentStatusResult;
}
