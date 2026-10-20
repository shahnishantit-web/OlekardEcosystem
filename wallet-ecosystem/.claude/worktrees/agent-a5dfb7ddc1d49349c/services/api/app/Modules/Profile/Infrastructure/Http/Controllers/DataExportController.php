<?php

declare(strict_types=1);

namespace App\Modules\Profile\Infrastructure\Http\Controllers;

use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Profile\Application\Commands\RequestDataExportCommand;
use App\Modules\Profile\Application\Queries\GetDataExportStatusQuery;
use App\Modules\Profile\Domain\Contracts\ProfilePhotoStorageContract;
use App\Modules\Profile\Domain\Exceptions\ExportInProgressException;
use App\Modules\Profile\Domain\Services\DataExportService;
use App\Modules\Profile\Infrastructure\Http\Resources\DataExportResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class DataExportController extends Controller
{
    public function __construct(
        private readonly DataExportService          $exportService,
        private readonly GetDataExportStatusQuery   $statusQuery,
        private readonly ProfilePhotoStorageContract $storage,
        private readonly ConfigurationServiceInterface $config,
    ) {}

    /**
     * POST /v1/users/profile/data-export
     * Request a personal data export. IDOR: userId from JWT only.
     */
    public function request(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId        = $request->attributes->get('identity.user_id');

        try {
            $command = new RequestDataExportCommand(
                userId:        $userId,
                correlationId: $correlationId,
            );

            $export = $this->exportService->request($command);

            return response()->json([
                'success' => true,
                'data'    => [
                    'export_id' => $export->id,
                    'status'    => $export->status,
                ],
            ], 200, ['X-Correlation-ID' => $correlationId]);

        } catch (ExportInProgressException) {
            return $this->errorResponse('EXPORT_IN_PROGRESS', 'An export is already in progress.', 409, $correlationId);
        }
    }

    /**
     * GET /v1/users/profile/data-export/{export_id}
     * Poll status of a requested export.
     * IDOR: export.user_id MUST match token user_id — mismatch → 403 FORBIDDEN (never 404).
     */
    public function status(Request $request, string $exportId): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId        = $request->attributes->get('identity.user_id');

        $export = $this->statusQuery->execute($exportId);

        if ($export === null || $export->userId !== $userId) {
            return $this->errorResponse('FORBIDDEN', 'Access denied.', 403, $correlationId);
        }

        $downloadUrl = null;
        if ($export->status === 'ready' && $export->downloadKey !== null) {
            $ttl         = (int) ($this->config->get('profile.photo.signed_url_ttl_seconds') ?? 86400);
            $downloadUrl = $this->storage->generateSignedUrl($export->downloadKey, $ttl);
        }

        return response()->json(
            (new DataExportResource($export, $downloadUrl))->toArray($request),
            200,
            ['X-Correlation-ID' => $correlationId],
        );
    }

    private function errorResponse(string $code, string $message, int $status, string $correlationId, array $details = []): JsonResponse
    {
        return response()->json([
            'success'        => false,
            'data'           => null,
            'error'          => ['code' => $code, 'message' => $message, 'details' => $details],
            'correlation_id' => $correlationId,
            'meta'           => null,
        ], $status, ['X-Correlation-ID' => $correlationId]);
    }
}
