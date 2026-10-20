<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Infrastructure\Http\Controllers;

use App\Modules\Reconciliation\Application\Services\ReconciliationImportService;
use App\Modules\Reconciliation\Domain\Adapters\ProviderTransactionDto;
use App\Modules\Reconciliation\Domain\Models\ReconciliationBatch;
use App\Modules\Reconciliation\Infrastructure\Http\Requests\ImportBankStatementRequest;
use App\Modules\Reconciliation\Infrastructure\Http\Requests\ImportProviderFileRequest;
use App\Modules\Reconciliation\Infrastructure\Http\Requests\ReceiveProviderApiRequest;
use App\Modules\Reconciliation\Infrastructure\Http\Resources\BatchDetailResource;
use App\Modules\Reconciliation\Infrastructure\Http\Resources\BatchSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ReconciliationBatchController
{
    public function __construct(private readonly ReconciliationImportService $importService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $batches = ReconciliationBatch::on('reconciliation')
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('source_type'), fn ($q, $t) => $q->where('source_type', $t))
            ->orderBy('created_at', 'desc')
            ->paginate((int) $request->input('per_page', 25));

        return BatchSummaryResource::collection($batches);
    }

    public function show(string $batchId): BatchDetailResource
    {
        $batch = ReconciliationBatch::on('reconciliation')->findOrFail($batchId);
        return new BatchDetailResource($batch);
    }

    public function importFile(ImportProviderFileRequest $request): JsonResponse
    {
        $batch = $this->importService->importFromFile(
            file:           $request->file('file'),
            idempotencyKey: $request->input('idempotency_key'),
            batchDate:      $request->input('batch_date'),
            submittedBy:    (string) $request->user()->id,
            providerCode:   $request->input('provider_code'),
        );

        return (new BatchDetailResource($batch))
            ->response()
            ->setStatusCode(202);
    }

    public function receiveApi(ReceiveProviderApiRequest $request): JsonResponse
    {
        $dtos = array_map(
            static fn (array $tx) => new ProviderTransactionDto(
                providerReference: $tx['provider_reference'],
                amount:            $tx['amount'],
                currencyCode:      $tx['currency_code'],
                transactionDate:   $tx['transaction_date'],
                itemType:          'provider_transaction',
            ),
            $request->input('transactions', [])
        );

        $batch = $this->importService->importFromApi(
            transactions:   $dtos,
            idempotencyKey: $request->input('idempotency_key'),
            batchDate:      $request->input('batch_date'),
            batchId:        $request->input('batch_id'),
            submittedBy:    (string) ($request->user()?->id ?? config('reconciliation.system_user_id', '00000000-0000-0000-0000-000000000000')),
            providerCode:   $request->input('provider_code'),
        );

        return (new BatchDetailResource($batch))
            ->response()
            ->setStatusCode(202);
    }

    public function importBankStatement(ImportBankStatementRequest $request): JsonResponse
    {
        $batch = $this->importService->importBankStatement(
            file:           $request->file('file'),
            idempotencyKey: $request->input('idempotency_key'),
            batchDate:      $request->input('batch_date'),
            submittedBy:    (string) $request->user()->id,
            statementId:    $request->input('statement_id'),
        );

        return (new BatchDetailResource($batch))
            ->response()
            ->setStatusCode(202);
    }
}
