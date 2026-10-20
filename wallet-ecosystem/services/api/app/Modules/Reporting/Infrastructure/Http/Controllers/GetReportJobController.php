<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Http\Controllers;

use App\Modules\Reporting\Application\Services\ReportJobApplicationService;
use App\Modules\Reporting\Domain\Exceptions\ReportJobNotFoundException;
use App\Modules\Reporting\Infrastructure\Http\Resources\ReportJobDetailResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GetReportJobController
{
    public function __construct(
        private readonly ReportJobApplicationService $service,
    ) {}

    public function __invoke(string $id, Request $request): ReportJobDetailResource|JsonResponse
    {
        $userId = (string) $request->attributes->get('identity.user_id', '');

        try {
            $job = $this->service->getJob($id, $userId);
        } catch (ReportJobNotFoundException) {
            return response()->json(['message' => 'Report job not found.', 'code' => 'REPORT_JOB_NOT_FOUND'], 404);
        }

        return new ReportJobDetailResource($job);
    }
}
