<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ReportJobNotFoundException extends NotFoundHttpException
{
    public function __construct(string $jobId = 'unknown')
    {
        parent::__construct("Report job [{$jobId}] not found.", code: 0);
    }

    public function getErrorCode(): string
    {
        return 'REPORT_JOB_NOT_FOUND';
    }
}
