<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Http\Controllers;

use App\Modules\Reporting\Application\Services\ReportExportApplicationService;
use App\Modules\Reporting\Infrastructure\Http\Resources\ReportExportResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class RequestExportController
{
    public function __construct(
        private readonly ReportExportApplicationService $service,
    ) {}

    public function __invoke(string $jobId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'format' => ['required', Rule::in(['csv', 'excel'])],
        ]);

        $userId        = (string) $request->attributes->get('identity.user_id', '');
        $correlationId = (string) Str::uuid();

        $export = $this->service->requestExport(
            jobId:         $jobId,
            userId:        $userId,
            format:        $validated['format'],
            correlationId: $correlationId,
        );

        return (new ReportExportResource($export))
            ->response()
            ->setStatusCode(202);
    }
}
