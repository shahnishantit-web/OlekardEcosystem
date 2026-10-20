<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Infrastructure\Http\Controllers;

use App\Modules\Reconciliation\Application\Services\ReconciliationMatchingService;
use App\Modules\Reconciliation\Domain\Models\ReconciliationBatch;
use App\Modules\Reconciliation\Domain\Models\ReconciliationItem;
use App\Modules\Reconciliation\Domain\Enums\ItemStatus;
use App\Modules\Reconciliation\Infrastructure\Http\Requests\MarkReconciledRequest;
use App\Modules\Reconciliation\Infrastructure\Http\Resources\BatchDetailResource;
use App\Modules\Reconciliation\Infrastructure\Http\Resources\ItemSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ReconciliationMatchingController
{
    public function __construct(private readonly ReconciliationMatchingService $matchingService) {}

    public function triggerMatch(Request $request, string $batchId): JsonResponse
    {
        $batch = $this->matchingService->triggerRematching($batchId, (string) $request->user()->id);

        return (new BatchDetailResource($batch))
            ->response()
            ->setStatusCode(202);
    }

    public function listItems(Request $request, string $batchId): AnonymousResourceCollection
    {
        $items = ReconciliationItem::on('reconciliation')
            ->where('batch_id', $batchId)
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('item_type'), fn ($q, $t) => $q->where('item_type', $t))
            ->orderBy('created_at', 'desc')
            ->paginate((int) $request->input('per_page', 25));

        return ItemSummaryResource::collection($items);
    }

    public function listUnmatched(Request $request, string $batchId): AnonymousResourceCollection
    {
        $query = ReconciliationItem::on('reconciliation')
            ->where('batch_id', $batchId)
            ->where('status', ItemStatus::Unmatched->value);

        if ($request->has('item_type')) {
            $query->where('item_type', $request->input('item_type'));
        }

        // US-RECON-010: unmatched deposits (positive amount)
        // US-RECON-011: unmatched withdrawals (negative amount)
        if ($request->has('amount_sign')) {
            if ($request->input('amount_sign') === 'positive') {
                $query->where('amount', '>', 0);
            } elseif ($request->input('amount_sign') === 'negative') {
                $query->where('amount', '<', 0);
            }
        }

        $items = $query->orderBy('created_at', 'desc')
            ->paginate((int) $request->input('per_page', 25));

        return ItemSummaryResource::collection($items);
    }

    public function markReconciled(MarkReconciledRequest $request, string $itemId): ItemSummaryResource
    {
        $item = $this->matchingService->markReconciled($itemId, (string) $request->user()->id);
        return new ItemSummaryResource($item);
    }
}
