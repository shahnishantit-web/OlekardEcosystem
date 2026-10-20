<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Http\Controllers;

use App\Modules\Admin\Application\Services\ExportService;
use App\Modules\Admin\Application\Services\TransactionAdminService;
use App\Modules\Admin\Infrastructure\Http\Requests\ExportRequest;
use App\Modules\Admin\Infrastructure\Http\Requests\SearchTransactionsRequest;
use App\Modules\Admin\Infrastructure\Http\Requests\TransactionActionRequest;
use App\Modules\Admin\Infrastructure\Http\Resources\InvestigationResource;
use App\Modules\Admin\Infrastructure\Http\Resources\TransactionActionResource;
use App\Modules\Admin\Infrastructure\Http\Resources\TransactionDetailResource;
use App\Modules\Admin\Infrastructure\Http\Resources\TransactionSummaryResource;
use App\Modules\Transaction\Domain\Exceptions\TransactionNotFoundException;
use App\Modules\Transaction\Domain\Exceptions\TransactionNotReversibleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class AdminTransactionController extends Controller
{
    public function __construct(
        private readonly TransactionAdminService $transactionService,
        private readonly ExportService           $exportService,
    ) {}

    public function list(SearchTransactionsRequest $request): JsonResponse
    {
        $paginator = $this->transactionService->searchTransactions(
            filters: $request->validated(),
            page:    (int) $request->query('page', 1),
            perPage: (int) $request->query('per_page', 20),
        );

        return response()->json([
            'data' => TransactionSummaryResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(string $transactionId): JsonResponse
    {
        try {
            $detail = $this->transactionService->getTransaction($transactionId);
        } catch (\DomainException $e) {
            if ($e->getMessage() === 'TRANSACTION_NOT_FOUND') {
                return response()->json(['message' => 'Transaction not found'], 404);
            }
            throw $e;
        }

        return response()->json(['data' => new TransactionDetailResource($detail)]);
    }

    public function investigate(TransactionActionRequest $request, string $transactionId): JsonResponse
    {
        try {
            $investigation = $this->transactionService->investigateFailedTransaction(
                transactionId: $transactionId,
                actorId:       $request->user()->id,
                ip:            $request->ip(),
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        return response()->json(['data' => new InvestigationResource($investigation)]);
    }

    public function reverse(TransactionActionRequest $request, string $transactionId): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (empty($idempotencyKey)) {
            return response()->json(['message' => 'Idempotency-Key header is required'], 400);
        }

        try {
            $reversal = $this->transactionService->reverseTransaction(
                transactionId:  $transactionId,
                idempotencyKey: $idempotencyKey,
                actorId:        $request->user()->id,
                reason:         $request->validated('reason'),
                ip:             $request->ip(),
            );
        } catch (TransactionNotFoundException $e) {
            return response()->json(['message' => 'Transaction not found'], 404);
        } catch (TransactionNotReversibleException $e) {
            return match (true) {
                str_contains($e->getMessage(), 'active reversal') => response()->json(['message' => $e->getMessage()], 409),
                default => response()->json(['message' => $e->getMessage()], 422),
            };
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => new TransactionActionResource($reversal)], 201);
    }

    public function refund(TransactionActionRequest $request, string $transactionId): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (empty($idempotencyKey)) {
            return response()->json(['message' => 'Idempotency-Key header is required'], 400);
        }

        try {
            $refund = $this->transactionService->refundTransaction(
                transactionId:  $transactionId,
                idempotencyKey: $idempotencyKey,
                actorId:        $request->user()->id,
                reason:         $request->validated('reason'),
                ip:             $request->ip(),
            );
        } catch (TransactionNotFoundException $e) {
            return response()->json(['message' => 'Transaction not found'], 404);
        } catch (TransactionNotReversibleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => 'Transaction type or status does not support refund'], 422);
        }

        return response()->json(['data' => new TransactionActionResource($refund)], 201);
    }

    public function export(ExportRequest $request): JsonResponse
    {
        $status = $this->exportService->requestExport(
            filters:     $request->validated(),
            format:      $request->exportFormat(),
            requestedBy: $request->user()->id,
        );

        return response()->json(['data' => $status], 202);
    }

    public function exportStatus(string $exportId): JsonResponse
    {
        $status = $this->exportService->getExportStatus($exportId);

        if ($status === null) {
            return response()->json(['message' => 'Export not found or expired'], 404);
        }

        return response()->json(['data' => $status]);
    }
}
