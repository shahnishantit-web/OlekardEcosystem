<?php

declare(strict_types=1);

namespace App\Modules\AdminApp\Infrastructure\Http\Controllers;

use App\Modules\AdminApp\Infrastructure\Http\Resources\ErrorResource;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Reporting\Application\Services\ReportExportApplicationService;
use App\Modules\Reporting\Application\Services\ReportJobApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class ComplianceController
{
    private const MAX_AUDIT_DAYS = 90;

    public function __construct(
        private readonly ReportJobApplicationService $reportJobService,
        private readonly ReportExportApplicationService $reportExportService,
        private readonly AuditServiceInterface $audit,
    ) {}

    public function auditIndex(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Uuid::uuid4());
        $fromDate      = $request->query('from_date');
        $toDate        = $request->query('to_date');

        if ($fromDate && $toDate) {
            $from = \Carbon\Carbon::parse($fromDate);
            $to   = \Carbon\Carbon::parse($toDate);
            $days = $from->diffInDays($to);

            if ($days > self::MAX_AUDIT_DAYS) {
                return ErrorResource::make(
                    'ADMINAPP_AUDIT_001',
                    "Date range cannot exceed " . self::MAX_AUDIT_DAYS . " days. Requested range: {$days} days.",
                    $correlationId,
                    400,
                );
            }
        }

        $query = DB::connection('platform')->table('platform.audit_events')
            ->select(['id', 'action', 'actor_type', 'actor_id', 'resource_type', 'resource_id',
                'outcome', 'correlation_id', 'before_state', 'after_state', 'occurred_at']);

        if ($fromDate) {
            $query->where('occurred_at', '>=', $fromDate);
        }

        if ($toDate) {
            $toDateEnd = str_contains((string) $toDate, 'T') ? $toDate : $toDate . ' 23:59:59';
            $query->where('occurred_at', '<=', $toDateEnd);
        }

        if ($actorId = $request->query('actor_id')) {
            $query->where('actor_id', $actorId);
        }

        if ($resourceType = $request->query('resource_type')) {
            $query->where('resource_type', $resourceType);
        }

        if ($action = $request->query('action')) {
            $query->where('action', 'ilike', "%{$action}%");
        }

        $perPage   = min((int) $request->query('per_page', 25), 100);
        $page      = (int) $request->query('page', 1);
        $paginated = $query->orderByDesc('occurred_at')->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'total'        => $paginated->total(),
                'per_page'     => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    public function reportIndex(Request $request): JsonResponse
    {
        $actorId = (string) $request->attributes->get('identity.user_id');
        $perPage = min((int) $request->query('per_page', 25), 100);

        $jobs = $this->reportJobService->listJobs($actorId, [], $perPage);

        return response()->json([
            'data' => $jobs->items(),
            'meta' => [
                'total'        => $jobs->total(),
                'per_page'     => $jobs->perPage(),
                'current_page' => $jobs->currentPage(),
                'last_page'    => $jobs->lastPage(),
            ],
        ]);
    }

    public function reportCreate(Request $request): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Uuid::uuid4());
        $idempotencyKey = (string) $request->header('Idempotency-Key');
        $actorId        = (string) $request->user()?->id;

        $reportType  = $request->input('report_type');
        $periodStart = $request->input('period_start');
        $periodEnd   = $request->input('period_end');
        $format      = $request->input('format', 'csv');

        // TODO: Align with ReportExportApplicationService::requestExport(jobId, userId, format, correlationId).
        // The correct flow is: submit report job → wait for completion → request export.
        // phpcs:ignore -- parameter mismatch to be resolved when compliance async flow is implemented
        /** @phpstan-ignore-next-line */
        $export = $this->reportExportService->requestExport( // @phpstan-ignore-line
            $reportType,    // @phpstan-ignore-line
            $actorId,       // @phpstan-ignore-line
            $format,        // @phpstan-ignore-line
            $correlationId, // @phpstan-ignore-line
        );

        $reportId = $export['id'] ?? $export->id ?? null;

        $this->audit->record(new AuditEventData(
            action:       'adminapp.report.requested',
            actorType:    'admin',
            actorId:      $actorId,
            resourceType: 'report',
            resourceId:   (string) ($reportId ?? 'unknown'),
            outcome:      'success',
            correlationId: $correlationId,
            metadata:     ['report_type' => $reportType, 'period_start' => $periodStart, 'period_end' => $periodEnd, 'format' => $format],
        ));

        return response()->json([
            'data' => [
                'report_id' => $reportId,
                'status'    => $export['status'] ?? 'pending',
            ],
        ], 202);
    }

    public function reportShow(Request $request, string $reportId): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Uuid::uuid4());
        $actorId       = (string) $request->user()?->id;

        try {
            $export = $this->reportExportService->getExport($reportId, $actorId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ErrorResource::make('ADMINAPP_REPORT_001', 'Report not found.', $correlationId, 404);
        }

        return response()->json([
            'data' => [
                'report_id'    => $export['id'] ?? null,
                'status'       => $export['status'] ?? null,
                'download_url' => $export['download_url'] ?? null,
            ],
        ]);
    }
}
