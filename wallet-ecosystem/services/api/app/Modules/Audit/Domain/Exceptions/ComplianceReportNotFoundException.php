<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class ComplianceReportNotFoundException extends HttpException
{
    public function __construct(string $reportId)
    {
        parent::__construct(404, 'Compliance report not found: '.$reportId, null, [], 0);
    }
}
