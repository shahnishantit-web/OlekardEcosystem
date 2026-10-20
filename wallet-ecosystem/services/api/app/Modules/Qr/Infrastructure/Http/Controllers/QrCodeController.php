<?php

declare(strict_types=1);

namespace App\Modules\Qr\Infrastructure\Http\Controllers;

use App\Modules\Qr\Application\Services\QrCodeService;
use App\Modules\Qr\Domain\DTO\GenerateQrCodeInput;
use App\Modules\Qr\Domain\Enums\QrCodeType;
use App\Modules\Qr\Infrastructure\Http\Requests\GenerateQrCodeRequest;
use App\Modules\Qr\Infrastructure\Http\Resources\QrCodeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class QrCodeController extends Controller
{
    public function __construct(private readonly QrCodeService $qrCodeService) {}

    public function generate(GenerateQrCodeRequest $request): JsonResponse
    {
        $merchantId    = (string) $request->attributes->get('identity.user_id');
        $correlationId = $request->header('X-Correlation-Id', (string) Str::uuid());
        $validated     = $request->validated();

        $input = new GenerateQrCodeInput(
            type:          QrCodeType::from($validated['type']),
            label:         $validated['label'] ?? null,
            amount:        $validated['amount'] ?? null,
            currency:      $validated['currency'] ?? 'BWP',
            merchantRef:   $validated['merchant_ref'] ?? null,
            expirySeconds: isset($validated['expiry_seconds']) ? (int) $validated['expiry_seconds'] : null,
        );

        $qrCode = $this->qrCodeService->generate($input, $merchantId, $correlationId);

        return (new QrCodeResource($qrCode, includePayload: true))
            ->response()
            ->setStatusCode(201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $merchantId = (string) $request->attributes->get('identity.user_id');

        $filters = array_filter([
            'status' => $request->query('status'),
            'type'   => $request->query('type'),
        ]);

        $paginator = $this->qrCodeService->listForMerchant($merchantId, $filters);

        return QrCodeResource::collection($paginator);
    }

    public function show(Request $request, string $id): QrCodeResource
    {
        $merchantId = (string) $request->attributes->get('identity.user_id');

        $qrCode = $this->qrCodeService->findForMerchant($id, $merchantId);

        return new QrCodeResource($qrCode);
    }

    public function deactivate(Request $request, string $id): QrCodeResource
    {
        $merchantId    = (string) $request->attributes->get('identity.user_id');
        $correlationId = $request->header('X-Correlation-Id', (string) Str::uuid());

        $qrCode = $this->qrCodeService->deactivate($id, $merchantId, $correlationId);

        return new QrCodeResource($qrCode);
    }
}
