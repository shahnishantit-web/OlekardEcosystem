<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Http\Controllers;

use App\Modules\Reporting\Application\Services\ReportExportApplicationService;
use App\Modules\Reporting\Infrastructure\Http\Resources\ReportExportResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

final class RegenerateLinkController
{
    public function __construct(
        private readonly ReportExportApplicationService $service,
    ) {}

    public function __invoke(string $exportId, Request $request): JsonResponse
    {
        $adminUserId   = (string) $request->attributes->get('identity.user_id', '');
        $correlationId = (string) Str::uuid();

        try {
            $export = $this->service->regenerateLink($exportId, $adminUserId, $correlationId);
        } catch (GoneHttpException) {
            return response()->json(
                ['message' => 'Export file has been purged or retention expired.', 'code' => 'EXPORT_PURGED'],
                410
            );
        }

        $ttlSeconds = (int) config('reports.export_link_ttl_seconds', 3600);
        $expiry     = $export->token_expires_at?->timestamp ?? now()->addSeconds($ttlSeconds)->timestamp;
        $downloadUrl = $export->isPurged() ? null
            : url("/v1/reports/exports/{$export->id}/download?token={$export->download_token}&expires={$expiry}");

        return (new ReportExportResource($export, $downloadUrl))
            ->response();
    }
}
