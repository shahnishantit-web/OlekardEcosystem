<?php

declare(strict_types=1);

namespace App\Modules\AdminApp\Infrastructure\Http\Controllers;

use App\Modules\AdminApp\Infrastructure\Http\Resources\ErrorResource;
use App\Modules\Reconciliation\Application\Services\SettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class ReconciliationController
{
    public function __construct(private readonly SettlementService $settlementService) {}

    public function index(Request $request): JsonResponse
    {
        $query = DB::connection('reconciliation')->table('reconciliation_batches');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($from = $request->query('from_date')) {
            $query->where('batch_date', '>=', $from);
        }

        if ($to = $request->query('to_date')) {
            $query->where('batch_date', '<=', $to);
        }

        $perPage   = min((int) $request->query('per_page', 25), 100);
        $page      = (int) $request->query('page', 1);
        $paginated = $query->orderByDesc('created_at')->paginate($perPage, ['*'], 'page', $page);

        $data = $paginated->getCollection()->map(fn ($b): array => [
            'batch_id'                       => $b->id,
            'period_start'                   => $b->batch_date,
            'period_end'                     => $b->batch_date,
            'status'                         => $b->status,
            'total_transactions'             => (int) ($b->total_count ?? 0),
            'matched_count'                  => (int) ($b->matched_count ?? 0),
            'unmatched_count'                => (int) ($b->unmatched_count ?? 0),
            'discrepancy_amount_minor_units' => 0,
            'currency_code'                  => 'BWP',
            'run_at'                         => $b->created_at,
            'completed_at'                   => $b->processed_at ?? null,
        ]);

        return response()->json([
            'data' => $data,
            'meta' => [
                'total'        => $paginated->total(),
                'per_page'     => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $batchId): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Uuid::uuid4());

        $batch = DB::connection('reconciliation')->table('reconciliation_batches')->where('id', $batchId)->first();

        if ($batch === null) {
            return ErrorResource::make('ADMINAPP_RECON_001', 'Reconciliation batch not found.', $correlationId, 404);
        }

        $unmatchedIds = DB::connection('reconciliation')->table('reconciliation_items')
            ->where('batch_id', $batchId)
            ->whereIn('status', ['unmatched', 'exception'])
            ->whereNotNull('internal_transaction_id')
            ->pluck('internal_transaction_id')
            ->toArray();

        return response()->json([
            'data' => [
                'batch_id'                       => $batch->id,
                'period_start'                   => $batch->batch_date,
                'period_end'                     => $batch->batch_date,
                'status'                         => $batch->status,
                'total_transactions'             => (int) ($batch->total_count ?? 0),
                'matched_count'                  => (int) ($batch->matched_count ?? 0),
                'unmatched_count'                => (int) ($batch->unmatched_count ?? 0),
                'discrepancy_amount_minor_units' => 0,
                'currency_code'                  => 'BWP',
                'run_at'                         => $batch->created_at,
                'completed_at'                   => $batch->processed_at ?? null,
                'unmatched_transaction_ids'      => $unmatchedIds,
            ],
        ]);
    }

    public function settlementIndex(Request $request): JsonResponse
    {
        $query = DB::connection('reconciliation')->table('settlement_batches');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($from = $request->query('from_date')) {
            $query->where('period_start', '>=', $from);
        }

        if ($to = $request->query('to_date')) {
            $query->where('period_end', '<=', $to);
        }

        $perPage   = min((int) $request->query('per_page', 25), 100);
        $page      = (int) $request->query('page', 1);
        $paginated = $query->orderByDesc('period_end')->paginate($perPage, ['*'], 'page', $page);

        $data = $paginated->getCollection()->map(fn ($s): array => $this->formatSettlementRow($s));

        return response()->json([
            'data' => $data,
            'meta' => [
                'total'        => $paginated->total(),
                'per_page'     => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    public function settlementShow(Request $request, string $settlementId): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Uuid::uuid4());

        $settlement = DB::connection('reconciliation')->table('settlement_batches')->where('id', $settlementId)->first();

        if ($settlement === null) {
            return ErrorResource::make('ADMINAPP_SETTLE_001', 'Settlement record not found.', $correlationId, 404);
        }

        return response()->json(['data' => $this->formatSettlementRow($settlement)]);
    }

    public function approveSettlement(Request $request, string $settlementId): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Uuid::uuid4());
        $checkerId      = (string) $request->attributes->get('identity.user_id', '');
        $idempotencyKey = $request->header('Idempotency-Key', '');

        try {
            $settlement = $this->settlementService->approveSettlement(
                $settlementId,
                $checkerId,
                $idempotencyKey,
            );
        } catch (NotFoundHttpException) {
            return ErrorResource::make('ADMINAPP_SETTLE_002', 'Settlement not found.', $correlationId, 404);
        } catch (UnprocessableEntityHttpException $e) {
            return ErrorResource::make('ADMINAPP_SETTLE_003', $e->getMessage(), $correlationId, 422);
        }

        $statusValue = $settlement->status instanceof \BackedEnum
            ? $settlement->status->value
            : (string) $settlement->status;

        return response()->json([
            'data' => [
                'settlement_id'              => $settlement->id,
                'settlement_date'            => $settlement->period_end,
                'status'                     => $statusValue,
                'total_amount_minor_units'   => (int) round((float) ($settlement->gross_amount ?? 0) * 100),
                'currency_code'              => $settlement->currency_code ?? 'BWP',
                'net_position_minor_units'   => (int) round((float) ($settlement->net_amount ?? 0) * 100),
                'provider'                   => $settlement->provider_code ?? null,
                'reference'                  => $settlement->external_reference ?? null,
            ],
        ]);
    }

    private function formatSettlementRow(object $s): array
    {
        return [
            'settlement_id'              => $s->id,
            'settlement_date'            => $s->period_end,
            'status'                     => $s->status,
            'total_amount_minor_units'   => (int) round((float) ($s->gross_amount ?? 0) * 100),
            'currency_code'              => $s->currency_code ?? 'BWP',
            'net_position_minor_units'   => (int) round((float) ($s->net_amount ?? 0) * 100),
            'provider'                   => $s->provider_code ?? null,
            'reference'                  => $s->external_reference ?? null,
        ];
    }
}
