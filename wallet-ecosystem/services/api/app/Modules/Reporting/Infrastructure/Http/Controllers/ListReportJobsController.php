<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Http\Controllers;

use App\Modules\Reporting\Application\Services\ReportJobApplicationService;
use App\Modules\Reporting\Infrastructure\Http\Requests\ListReportJobsRequest;
use App\Modules\Reporting\Infrastructure\Http\Resources\ReportJobSummaryResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListReportJobsController
{
    public function __construct(
        private readonly ReportJobApplicationService $service,
    ) {}

    public function __invoke(ListReportJobsRequest $request): AnonymousResourceCollection
    {
        $userId  = (string) $request->attributes->get('identity.user_id', '');
        $perPage = (int) $request->validated('per_page', 20);

        $jobs = $this->service->listJobs($userId, $request->validated(), $perPage);

        return ReportJobSummaryResource::collection($jobs);
    }
}
