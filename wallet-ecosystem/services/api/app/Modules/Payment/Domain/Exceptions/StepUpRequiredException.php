<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

final class StepUpRequiredException extends \RuntimeException
{
    public function __construct(
        private readonly string $challengeType = 'pin',
        private readonly string $paymentIntentId = ''
    ) {
        parent::__construct(
            "Step-up authentication is required for this payment amount. "
            . "Challenge type: [{$challengeType}]."
        );
    }

    public function getChallengeType(): string
    {
        return $this->challengeType;
    }

    public function getPaymentIntentId(): string
    {
        return $this->paymentIntentId;
    }
}
