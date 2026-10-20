<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Http\Controllers;

use App\Modules\Reporting\Application\Services\ReportExportApplicationService;
use App\Modules\Reporting\Infrastructure\Http\Resources\ReportExportResource;
use App\Modules\Reporting\Infrastructure\Models\ReportExportModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

final class GetExportController
{
    public function __construct(
        private readonly ReportExportApplicationService $service,
    ) {}

    public function __invoke(string $exportId, Request $request): JsonResponse
    {
        $userId = (string) $request->attributes->get('identity.user_id', '');

        try {
            $result = $this->service->getExport($exportId, $userId);
        } catch (GoneHttpException) {
            return response()->json(
                ['message' => 'Export file has been purged.', 'code' => 'EXPORT_PURGED'],
                410
            );
        }

        /** @var ReportExportModel $export */
        $export = $result['export'];

        return (new ReportExportResource($export, $result['download_url']))
            ->response();
    }
}
