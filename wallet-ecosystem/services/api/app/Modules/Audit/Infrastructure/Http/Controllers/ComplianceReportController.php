<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Http\Controllers;

use App\Modules\Audit\Application\Services\ComplianceReportService;
use App\Modules\Audit\Infrastructure\Http\Requests\GenerateComplianceReportRequest;
use App\Modules\Audit\Infrastructure\Http\Resources\ComplianceReportResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class ComplianceReportController extends Controller
{
    public function __construct(
        private readonly ComplianceReportService $service,
    ) {}

    public function store(GenerateComplianceReportRequest $request): JsonResponse
    {

        $idempotencyKey = $request->header('Idempotency-Key')
            ?? abort(422, 'Idempotency-Key header is required');

        $actor = $request->user() ?? abort(401);

        $report = $this->service->requestReport(
            reportType:      $request->input('report_type'),
            dateRangeStart:  $request->input('date_range_start'),
            dateRangeEnd:    $request->input('date_range_end'),
            filters:         $request->input('filters', []),
            idempotencyKey:  $idempotencyKey,
            actorId:         (string) $actor->id,
            correlationId:   $idempotencyKey,
        );

        $status = $report->status === 'completed' ? 200 : 202;

        return response()->json(['data' => new ComplianceReportResource($report)], $status);
    }

    public function show(string $id): JsonResponse
    {

        return response()->json([
            'data' => new ComplianceReportResource($this->service->getReport($id)),
        ]);
    }
}
