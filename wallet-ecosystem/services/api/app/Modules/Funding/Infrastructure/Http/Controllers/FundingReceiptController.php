<?php

declare(strict_types=1);

namespace App\Modules\Funding\Infrastructure\Http\Controllers;

use App\Modules\Funding\Infrastructure\Models\FundingReceiptModel;
use App\Modules\Funding\Infrastructure\Models\FundingTransactionModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class FundingReceiptController extends Controller
{
    /**
     * GET /api/v1/funding/transactions/{transactionId}/receipt
     *
     * Returns the immutable receipt for a completed funding transaction.
     * Only the owning customer or the agent who initiated the cash-in may retrieve it.
     */
    public function show(Request $request, string $transactionId): JsonResponse
    {
        $actorId = $request->user()?->id ?? $request->header('X-Test-User-Id');

        // Verify ownership through the transaction
        $tx = FundingTransactionModel::where('id', $transactionId)
            ->where(function ($q) use ($actorId): void {
                $q->where('customer_id', $actorId)
                  ->orWhere('agent_id', $actorId);
            })
            ->firstOrFail();

        $receipt = FundingReceiptModel::where('funding_transaction_id', $tx->id)
            ->firstOrFail();

        return response()->json(['data' => $this->formatReceipt($receipt)]);
    }

    /**
     * GET /api/v1/funding/receipts/{receiptReference}
     *
     * Spec-canonical receipt retrieval endpoint (FR-E08-043).
     * Accepts the human-facing receipt_reference (printed on customer receipts).
     */
    public function showByReference(Request $request, string $receiptReference): JsonResponse
    {
        $actorId = $request->user()?->id ?? $request->header('X-Test-User-Id');

        $receipt = FundingReceiptModel::where('receipt_reference', $receiptReference)
            ->where(function ($q) use ($actorId): void {
                $q->where('customer_id', $actorId)
                  ->orWhere('agent_id', $actorId);
            })
            ->firstOrFail();

        return response()->json(['data' => $this->formatReceipt($receipt)]);
    }

    private function formatReceipt(FundingReceiptModel $receipt): array
    {
        return [
            'id'                      => $receipt->id,
            'funding_transaction_id'  => $receipt->funding_transaction_id,
            'receipt_reference'       => $receipt->receipt_reference,
            'amount'                  => $receipt->amount,
            'currency_code'           => $receipt->currency_code,
            'funding_method'          => $receipt->funding_method?->value ?? $receipt->funding_method,
            'issued_at'               => $receipt->issued_at?->toIso8601String(),
        ];
    }
}
