<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Http\Controllers;

use App\Modules\Reporting\Application\Services\ReportJobApplicationService;
use App\Modules\Reporting\Domain\Exceptions\ReportJobCancelException;
use App\Modules\Reporting\Domain\Exceptions\ReportJobNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class CancelReportJobController
{
    public function __construct(
        private readonly ReportJobApplicationService $service,
    ) {}

    public function __invoke(string $id, Request $request): JsonResponse
    {
        $userId        = (string) $request->attributes->get('identity.user_id', '');
        $correlationId = (string) Str::uuid();

        try {
            $this->service->cancel($id, $userId, $correlationId);
        } catch (ReportJobNotFoundException) {
            return response()->json(['message' => 'Report job not found.', 'code' => 'REPORT_JOB_NOT_FOUND'], 404);
        } catch (ReportJobCancelException) {
            return response()->json(['message' => 'Report job cannot be cancelled in its current status.', 'code' => 'REPORT_JOB_CANNOT_CANCEL'], 409);
        }

        return response()->json(null, 204);
    }
}
