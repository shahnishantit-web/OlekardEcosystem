<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\Contracts;

use App\Modules\Risk\Domain\Models\SuspiciousActivityReport;

interface FiaReportingInterface
{
    /**
     * Submit a SAR to the FIA portal.
     *
     * @return string FIA reference number on success
     * @throws \RuntimeException on submission failure
     */
    public function submit(SuspiciousActivityReport $sar): string;
}
