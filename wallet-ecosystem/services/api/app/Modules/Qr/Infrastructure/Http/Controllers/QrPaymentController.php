<?php

declare(strict_types=1);

namespace App\Modules\Qr\Infrastructure\Http\Controllers;

use App\Modules\Qr\Application\Services\QrPaymentService;
use App\Modules\Qr\Application\Services\QrReceiptService;
use App\Modules\Qr\Domain\DTO\ExecuteQrPaymentCommand;
use App\Modules\Qr\Domain\Exceptions\ReceiptNotAvailableException;
use App\Modules\Qr\Infrastructure\Http\Requests\ExecuteQrPaymentRequest;
use App\Modules\Qr\Infrastructure\Http\Requests\QrFeeQuoteRequest;
use App\Modules\Qr\Infrastructure\Http\Requests\ResolveQrRequest;
use App\Modules\Qr\Infrastructure\Http\Resources\QrFeeQuoteResource;
use App\Modules\Qr\Infrastructure\Http\Resources\QrPaymentResource;
use App\Modules\Qr\Infrastructure\Http\Resources\QrReceiptResource;
use App\Modules\Qr\Infrastructure\Http\Resources\QrResolutionResource;
use App\Modules\Qr\Infrastructure\Models\QrPaymentTransactionModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class QrPaymentController extends Controller
{
    public function __construct(
        private readonly QrPaymentService $paymentService,
        private readonly QrReceiptService $receiptService,
    ) {}

    public function resolve(ResolveQrRequest $request): QrResolutionResource
    {
        $customerId    = (string) $request->attributes->get('identity.user_id');
        $correlationId = $request->header('X-Correlation-Id', (string) Str::uuid());

        $result = $this->paymentService->resolve(
            encodedPayload: $request->validated()['payload'],
            customerId:     $customerId,
            correlationId:  $correlationId,
        );

        return new QrResolutionResource($result);
    }

    public function quote(QrFeeQuoteRequest $request): QrFeeQuoteResource
    {
        $correlationId = $request->header('X-Correlation-Id', (string) Str::uuid());
        $validated     = $request->validated();

        $result = $this->paymentService->quote(
            qrCodeId:      $validated['qr_code_id'],
            amountStr:     (string) $validated['amount'],
            currency:      $validated['currency'],
            correlationId: $correlationId,
        );

        return new QrFeeQuoteResource($result);
    }

    public function confirm(ExecuteQrPaymentRequest $request): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        if (empty($idempotencyKey)) {
            return response()->json(['message' => 'Idempotency-Key header is required.'], 422);
        }

        $customerId    = (string) $request->attributes->get('identity.user_id');
        $correlationId = $request->header('X-Correlation-Id', (string) Str::uuid());
        $validated     = $request->validated();

        $cmd = new ExecuteQrPaymentCommand(
            qrCodeId:         $validated['qr_code_id'],
            customerWalletId: $validated['customer_wallet_id'],
            amount:           isset($validated['amount']) ? (string) $validated['amount'] : null,
            currency:         $validated['currency'],
            idempotencyKey:   $idempotencyKey,
            correlationId:    $correlationId,
            stepUpToken:      $validated['step_up_token'] ?? null,
        );

        // Validate wallet ownership server-side (FR-E16-009)
        // QrPaymentService::confirm() validates wallet owner matches $customerId via WalletModuleContract
        $result = $this->paymentService->confirm($cmd);

        return (new QrPaymentResource($result))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, string $id): QrPaymentResource
    {
        $customerId = (string) $request->attributes->get('identity.user_id');

        /** @var QrPaymentTransactionModel $tx */
        $tx = QrPaymentTransactionModel::where('id', $id)
            ->where('customer_wallet_id', $customerId)
            ->firstOrFail();

        return new QrPaymentResource($tx);
    }

    public function receipt(Request $request, string $id): QrReceiptResource|JsonResponse
    {
        $customerId = (string) $request->attributes->get('identity.user_id');

        try {
            $receipt = $this->receiptService->findForCustomer($id, $customerId);
        } catch (ReceiptNotAvailableException $e) {
            return response()->json([
                'error' => $e->errorCode,
                'message' => $e->getMessage(),
            ], $e->httpStatus);
        }

        return new QrReceiptResource($receipt);
    }
}
