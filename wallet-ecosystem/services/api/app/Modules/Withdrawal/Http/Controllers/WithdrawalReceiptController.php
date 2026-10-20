<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Http\Controllers;

use App\Modules\Withdrawal\Http\Resources\WithdrawalReceiptResource;
use App\Modules\Withdrawal\Infrastructure\Models\WithdrawalReceiptModel;
use App\Modules\Withdrawal\Infrastructure\Models\WithdrawalTransactionModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class WithdrawalReceiptController extends Controller
{
    public function show(Request $request, string $id): JsonResponse
    {
        $customerId = $request->user()?->id ?? $request->header('X-Test-User-Id');

        $tx = WithdrawalTransactionModel::where('id', $id)
            ->where('customer_id', $customerId)
            ->first();

        if ($tx === null) {
            return response()->json(['message' => 'Withdrawal not found.'], 404);
        }

        $receipt = WithdrawalReceiptModel::where('withdrawal_transaction_id', $id)->first();

        if ($receipt === null) {
            return response()->json(['message' => 'Receipt not available.'], 404);
        }

        return response()->json(new WithdrawalReceiptResource($receipt), 200);
    }

    public function showByReference(Request $request, string $receiptReference): JsonResponse
    {
        $customerId = $request->user()?->id ?? $request->header('X-Test-User-Id');

        $receipt = WithdrawalReceiptModel::where('receipt_reference', $receiptReference)
            ->where('customer_id', $customerId)
            ->first();

        if ($receipt === null) {
            return response()->json(['message' => 'Receipt not found.'], 404);
        }

        return response()->json(new WithdrawalReceiptResource($receipt), 200);
    }
}
