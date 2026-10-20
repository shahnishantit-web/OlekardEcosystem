<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ReportJobCancelException extends ConflictHttpException
{
    public function __construct(string $jobId)
    {
        parent::__construct("Report job [{$jobId}] cannot be cancelled in its current status.", code: 0);
    }

    public function getErrorCode(): string
    {
        return 'REPORT_JOB_CANNOT_CANCEL';
    }
}
