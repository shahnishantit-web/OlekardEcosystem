<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Admin;

use App\Modules\Payment\Application\Services\PaymentExportService;
use App\Modules\Payment\Http\Requests\CreatePaymentExportRequest;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

final class PaymentExportAdminController extends Controller
{
    public function __construct(
        private readonly PaymentExportService   $exportService,
        private readonly AuditServiceInterface  $audit,
    ) {
    }

    public function request(CreatePaymentExportRequest $r): JsonResponse
    {
        /** @var \App\Modules\Identity\Infrastructure\Models\UserModel $user */
        $user     = $r->user();
        $validated = $r->validated();

        $model = $this->exportService->request(
            requestorId: $user->id,
            scopeType:   $validated['scope_type'],
            orgId:       $validated['organisation_id'] ?? null,
            filters:     $validated['filters'] ?? [],
        );

        return response()->json([
            'data' => [
                'export_id' => $model->id,
                'status'    => $model->status,
            ],
        ], 202);
    }

    public function status(Request $request, string $exportId): JsonResponse
    {
        /** @var \App\Modules\Identity\Infrastructure\Models\UserModel $user */
        $user = $request->user();

        $model = $this->exportService->getStatus($exportId, $user->id);

        $downloadUrl = null;

        if ($model->status->isDownloadable() && $model->token !== null) {
            $downloadUrl = url("/api/v1/payment/exports/download/{$model->token}");
        }

        return response()->json([
            'data' => [
                'export_id'    => $model->id,
                'status'       => $model->status,
                'row_count'    => $model->row_count,
                'generated_at' => $model->generated_at?->toIso8601String(),
                'download_url' => $downloadUrl,
                'expires_at'   => $model->token_expires_at?->toIso8601String(),
            ],
        ]);
    }

    public function download(Request $request, string $token): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        /** @var \App\Modules\Identity\Infrastructure\Models\UserModel|null $user */
        $user = $request->user();

        $model = $this->exportService->redeemToken($token);

        // Audit the download access (FR-020)
        $this->audit->record(new AuditEventData(
            action:        'payment.export.downloaded',
            actorType:     $user !== null ? 'admin' : 'token',
            resourceType:  'payment_export',
            correlationId: $model->id,
            outcome:       'success',
            actorId:       $user?->id ?? 'token-auth',
            resourceId:    $model->id,
            afterState:    ['row_count' => $model->row_count],
        ));

        $path = "exports/payment/{$model->id}.csv";

        return Storage::download($path, "payment-export-{$model->id}.csv", [
            'Content-Type' => 'text/csv',
        ]);
    }
}
