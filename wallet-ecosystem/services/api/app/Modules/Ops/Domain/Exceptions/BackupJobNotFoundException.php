<?php

declare(strict_types=1);

namespace App\Modules\Ops\Domain\Exceptions;

use RuntimeException;

final class BackupJobNotFoundException extends RuntimeException
{
    public readonly string $errorCode;
    public readonly int $httpStatus;

    public function __construct(string $id)
    {
        parent::__construct("Backup job '{$id}' not found.");
        $this->errorCode  = 'OPS_BACKUP_JOB_NOT_FOUND';
        $this->httpStatus = 404;
    }
}
