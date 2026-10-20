<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Infrastructure\Http\Controllers;

use App\Modules\Transaction\Application\Services\ReceiptService;
use App\Modules\Transaction\Domain\Exceptions\TransactionNotFoundException;
use App\Modules\Transaction\Infrastructure\Http\Requests\ShareReceiptRequest;
use App\Modules\Transaction\Infrastructure\Http\Resources\TransactionReceiptResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class ReceiptController extends Controller
{
    public function __construct(
        private readonly ReceiptService $receiptService,
    ) {}

    /** GET /api/v1/transactions/{id}/receipt */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $receipt = $this->receiptService->getOrCreate($id);

            return response()->json(['data' => new TransactionReceiptResource($receipt)]);
        } catch (TransactionNotFoundException $e) {
            return response()->json(['error' => ['code' => 'TRANSACTION_NOT_FOUND', 'message' => $e->getMessage()]], 404);
        }
    }

    /** POST /api/v1/transactions/{id}/receipt/share */
    public function share(ShareReceiptRequest $request, string $id): JsonResponse
    {
        $actorId = (string) ($request->user()?->id ?? $request->header('X-Test-User-Id') ?? '');

        try {
            $this->receiptService->share(
                transactionId: $id,
                channel:       $request->input('channel'),
                recipient:     $request->input('recipient'),
                actorId:       $actorId,
            );

            return response()->json(['data' => ['message' => 'Receipt shared successfully.']], 200);
        } catch (TransactionNotFoundException $e) {
            return response()->json(['error' => ['code' => 'TRANSACTION_NOT_FOUND', 'message' => $e->getMessage()]], 404);
        }
    }
}
