<?php

declare(strict_types=1);

namespace App\Modules\Ops\Domain\Exceptions;

use RuntimeException;

final class DeploymentVersionNotFoundException extends RuntimeException
{
    public readonly string $errorCode;
    public readonly int $httpStatus;

    public function __construct(string $id)
    {
        parent::__construct("Deployment version '{$id}' not found.");
        $this->errorCode  = 'OPS_DEPLOYMENT_VERSION_NOT_FOUND';
        $this->httpStatus = 404;
    }
}
