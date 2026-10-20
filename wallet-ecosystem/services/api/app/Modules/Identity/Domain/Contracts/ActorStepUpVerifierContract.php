<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

/** Financial callers require signed proof bound to both current actor and session. */
interface ActorStepUpVerifierContract
{
    public function verifyStepUpForActor(string $token, string $scope, string $actorId, string $sessionId): bool;
}
