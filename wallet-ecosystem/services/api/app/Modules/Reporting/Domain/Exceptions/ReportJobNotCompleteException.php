<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ReportJobNotCompleteException extends ConflictHttpException
{
    public function __construct(string $jobId = 'unknown')
    {
        parent::__construct("Report job [{$jobId}] is not in 'complete' status.", code: 0);
    }

    public function getErrorCode(): string
    {
        return 'REPORT_JOB_NOT_COMPLETE';
    }
}
