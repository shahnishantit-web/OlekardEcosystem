<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Infrastructure\Http\Controllers;

use App\Modules\Transfer\Infrastructure\Http\Resources\TransferReceiptResource;
use App\Modules\Transfer\Infrastructure\Models\TransferReceiptModel;
use App\Modules\Transfer\Infrastructure\Models\TransferTransactionModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class TransferReceiptController extends Controller
{
    /** GET /api/v1/transfers/{transferId}/receipt */
    public function show(Request $request, string $transferId): JsonResponse
    {
        $actorId = $request->user()?->id ?? $request->header('X-Test-User-Id');

        $tx = TransferTransactionModel::find($transferId);

        if ($tx === null
            || ($tx->sender_customer_id !== $actorId && $tx->recipient_customer_id !== $actorId)) {
            return response()->json([
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Transfer not found.'],
            ], 404);
        }

        $receipt = TransferReceiptModel::where('transfer_transaction_id', $transferId)->first();

        if ($receipt === null) {
            return response()->json([
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Receipt not yet available.'],
            ], 404);
        }

        return response()->json(['data' => new TransferReceiptResource($receipt)]);
    }

    /** GET /api/v1/transfers/receipts/{receiptReference} */
    public function showByReference(Request $request, string $receiptReference): JsonResponse
    {
        $actorId = $request->user()?->id ?? $request->header('X-Test-User-Id');

        $receipt = TransferReceiptModel::where('receipt_reference', $receiptReference)->first();

        if ($receipt === null
            || ($receipt->sender_customer_id !== $actorId && $receipt->recipient_customer_id !== $actorId)) {
            return response()->json([
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Receipt not found.'],
            ], 404);
        }

        return response()->json(['data' => new TransferReceiptResource($receipt)]);
    }
}
