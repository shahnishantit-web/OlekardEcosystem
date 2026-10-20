<?php

declare(strict_types=1);

namespace App\Modules\Ops\Http\Controllers\Admin;

use App\Modules\Ops\Application\Services\LogService;
use App\Modules\Ops\Domain\Enums\LogSeverity;
use App\Modules\Ops\Http\Controllers\OpsBaseController;
use App\Modules\Ops\Http\Resources\LogEntryResource;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminLogController extends OpsBaseController
{
    public function __construct(
        private readonly LogService $logService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) \Illuminate\Support\Str::uuid());

        $severity = null;
        if ($request->filled('severity')) {
            $severity = LogSeverity::tryFrom($request->input('severity'));
        }

        $paginator = $this->logService->search(
            correlationId: $request->input('correlation_id'),
            severity:      $severity,
            serviceName:   $request->input('service_name'),
            from:          $request->filled('from') ? Carbon::parse($request->input('from')) : null,
            to:            $request->filled('to')   ? Carbon::parse($request->input('to'))   : null,
            perPage:       (int) $request->input('per_page', 50),
            cursor:        $request->input('cursor'),
        );

        return $this->paginatedOk(
            $paginator->through(fn ($item) => new LogEntryResource($item)),
            $correlationId,
        );
    }
}
