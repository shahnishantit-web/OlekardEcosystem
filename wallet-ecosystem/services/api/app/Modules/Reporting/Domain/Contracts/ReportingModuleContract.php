<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Contracts;

use App\Modules\Reporting\Domain\Enums\ReportJobStatus;
use Illuminate\Pagination\LengthAwarePaginator;

interface ReportingModuleContract
{
    public function getJobStatus(string $jobId): ?ReportJobStatus;

    public function listJobsForUser(string $userId, array $filters): LengthAwarePaginator;
}
