<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

final readonly class RequestStepUpCommand
{
    public function __construct(
        public string $userId,
        public string $sessionId,
        public string $actorType,
        public string $scope,
        public float  $amount,
        public string $currency,
        public bool   $riskRequired,
        public string $correlationId,
    ) {}
}
