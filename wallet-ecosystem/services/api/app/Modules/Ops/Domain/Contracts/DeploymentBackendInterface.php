<?php

declare(strict_types=1);

namespace App\Modules\Ops\Domain\Contracts;

interface DeploymentBackendInterface
{
    /** Initiate a rollback from $fromVersion to $toVersion. */
    public function rollback(string $fromVersion, string $toVersion): void;
}
