<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Adapters;

use App\Modules\Risk\Domain\Contracts\FiaReportingInterface;
use App\Modules\Risk\Domain\Models\SuspiciousActivityReport;

final class FiaReportingStub implements FiaReportingInterface
{
    public function submit(SuspiciousActivityReport $sar): string
    {
        $sar->increment('submission_attempts');

        throw new \RuntimeException(
            'FIA portal integration not yet implemented — deferred to E28/E1000. SAR id: '.$sar->id
        );
    }
}
