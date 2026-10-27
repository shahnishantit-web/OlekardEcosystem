<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Domain\ValueObjects\PaymentAttemptResult;
use App\Modules\Payment\Domain\ValueObjects\PaymentInitiateRequest;

interface InitiatesPayment
{
    public function initiatePayment(PaymentInitiateRequest $request): PaymentAttemptResult;
}
