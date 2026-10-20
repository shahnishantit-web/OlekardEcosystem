<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Application\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Reconciliation\Application\Jobs\ProcessReconciliationBatchJob;
use App\Modules\Reconciliation\Domain\Adapters\ProviderFileAdapterInterface;
use App\Modules\Reconciliation\Domain\Adapters\ProviderTransactionDto;
use App\Modules\Reconciliation\Domain\Enums\BatchSourceType;
use App\Modules\Reconciliation\Domain\Enums\BatchStatus;
use App\Modules\Reconciliation\Domain\Enums\ItemStatus;
use App\Modules\Reconciliation\Domain\Models\ReconciliationBatch;
use App\Modules\Reconciliation\Domain\Models\ReconciliationItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ReconciliationImportService
{
    public function __construct(
        private readonly ProviderFileAdapterInterface $fileAdapter,
        private readonly AuditServiceInterface $audit,
    ) {}

    public function importFromFile(
        UploadedFile $file,
        string $idempotencyKey,
        string $batchDate,
        string $submittedBy,
        ?string $providerCode = null,
    ): ReconciliationBatch {
        $existing = ReconciliationBatch::on('reconciliation')
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $filePath = Storage::disk('reconciliation_imports')
            ->putFileAs('batches', $file, Str::uuid() . '.' . $file->getClientOriginalExtension());

        $stream = fopen(Storage::disk('reconciliation_imports')->path($filePath), 'rb');
        $dtos   = $this->fileAdapter->parse($stream, $file->getMimeType() ?? 'text/csv');
        fclose($stream);

        return DB::connection('reconciliation')->transaction(function () use (
            $idempotencyKey, $batchDate, $submittedBy, $providerCode, $filePath, $dtos
        ): ReconciliationBatch {
            $batchId = (string) Str::orderedUuid();

            $batch = ReconciliationBatch::on('reconciliation')->create([
                'id'               => $batchId,
                'idempotency_key'  => $idempotencyKey,
                'source_type'      => BatchSourceType::ProviderFile->value,
                'source_reference' => basename($filePath),
                'batch_date'       => $batchDate,
                'provider_code'    => $providerCode,
                'status'           => BatchStatus::Processing->value,
                'total_count'      => count($dtos),
                'file_path'        => $filePath,
                'submitted_by'     => $submittedBy,
                'metadata'         => [],
            ]);

            $this->createItems($batch, $dtos);

            ProcessReconciliationBatchJob::dispatch((string) $batch->id);

            Log::info('reconciliation.batch.created', [
                'batch_id'    => $batch->id,
                'source_type' => BatchSourceType::ProviderFile->value,
                'total_count' => count($dtos),
            ]);

            $this->audit->record(new AuditEventData(
                action:       'reconciliation.batch.created',
                actorType:    'admin',
                resourceType: 'reconciliation_batch',
                correlationId: $batchId,
                outcome:      'success',
                actorId:      $submittedBy,
                resourceId:   $batchId,
                afterState:   ['source_type' => BatchSourceType::ProviderFile->value, 'total_count' => count($dtos)],
            ));

            return $batch;
        });
    }

    /**
     * @param  ProviderTransactionDto[]  $transactions
     */
    public function importFromApi(
        array $transactions,
        string $idempotencyKey,
        string $batchDate,
        string $batchId,
        string $submittedBy,
        ?string $providerCode = null,
    ): ReconciliationBatch {
        $existing = ReconciliationBatch::on('reconciliation')
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::connection('reconciliation')->transaction(function () use (
            $transactions, $idempotencyKey, $batchDate, $batchId, $submittedBy, $providerCode
        ): ReconciliationBatch {
            $batchId = $batchId ?: (string) Str::orderedUuid();

            $batch = ReconciliationBatch::on('reconciliation')->create([
                'id'               => $batchId,
                'idempotency_key'  => $idempotencyKey,
                'source_type'      => BatchSourceType::ProviderApi->value,
                'source_reference' => $batchId,
                'batch_date'       => $batchDate,
                'provider_code'    => $providerCode,
                'status'           => BatchStatus::Processing->value,
                'total_count'      => count($transactions),
                'submitted_by'     => $submittedBy,
                'metadata'         => [],
            ]);

            $this->createItems($batch, $transactions);

            ProcessReconciliationBatchJob::dispatch((string) $batch->id);

            Log::info('reconciliation.batch.created', [
                'batch_id'    => $batch->id,
                'source_type' => BatchSourceType::ProviderApi->value,
            ]);

            $this->audit->record(new AuditEventData(
                action:        'reconciliation.batch.created',
                actorType:     'system',
                resourceType:  'reconciliation_batch',
                correlationId: $batchId,
                outcome:       'success',
                actorId:       $submittedBy,
                resourceId:    $batchId,
                afterState:    ['source_type' => BatchSourceType::ProviderApi->value],
            ));

            return $batch;
        });
    }

    public function importBankStatement(
        UploadedFile $file,
        string $idempotencyKey,
        string $batchDate,
        string $submittedBy,
        ?string $statementId = null,
    ): ReconciliationBatch {
        $existing = ReconciliationBatch::on('reconciliation')
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $filePath = Storage::disk('reconciliation_imports')
            ->putFileAs('bank_statements', $file, Str::uuid() . '.' . $file->getClientOriginalExtension());

        $stream = fopen(Storage::disk('reconciliation_imports')->path($filePath), 'rb');
        $dtos   = $this->fileAdapter->parse($stream, $file->getMimeType() ?? 'text/csv');
        fclose($stream);

        // Coerce all items to bank_transaction type
        $dtos = array_map(static fn (ProviderTransactionDto $dto) => new ProviderTransactionDto(
            providerReference: $dto->providerReference,
            amount:            $dto->amount,
            currencyCode:      $dto->currencyCode,
            transactionDate:   $dto->transactionDate,
            itemType:          'bank_transaction',
            metadata:          $dto->metadata,
        ), $dtos);

        return DB::connection('reconciliation')->transaction(function () use (
            $idempotencyKey, $batchDate, $submittedBy, $statementId, $filePath, $dtos
        ): ReconciliationBatch {
            $batchId = (string) Str::orderedUuid();

            $batch = ReconciliationBatch::on('reconciliation')->create([
                'id'               => $batchId,
                'idempotency_key'  => $idempotencyKey,
                'source_type'      => BatchSourceType::BankStatement->value,
                'source_reference' => $statementId ?? basename($filePath),
                'batch_date'       => $batchDate,
                'status'           => BatchStatus::Processing->value,
                'total_count'      => count($dtos),
                'file_path'        => $filePath,
                'submitted_by'     => $submittedBy,
                'metadata'         => [],
            ]);

            $this->createItems($batch, $dtos);

            ProcessReconciliationBatchJob::dispatch((string) $batch->id);

            Log::info('reconciliation.batch.created', [
                'batch_id'    => $batch->id,
                'source_type' => BatchSourceType::BankStatement->value,
            ]);

            $this->audit->record(new AuditEventData(
                action:        'reconciliation.batch.created',
                actorType:     'admin',
                resourceType:  'reconciliation_batch',
                correlationId: $batchId,
                outcome:       'success',
                actorId:       $submittedBy,
                resourceId:    $batchId,
                afterState:    ['source_type' => BatchSourceType::BankStatement->value],
            ));

            return $batch;
        });
    }

    /**
     * @param  ProviderTransactionDto[]  $dtos
     */
    private function createItems(ReconciliationBatch $batch, array $dtos): void
    {
        foreach ($dtos as $dto) {
            ReconciliationItem::on('reconciliation')->create([
                'id'                 => (string) Str::orderedUuid(),
                'batch_id'           => $batch->id,
                'item_type'          => $dto->itemType,
                'provider_reference' => $dto->providerReference,
                'amount'             => $dto->amount,
                'currency_code'      => $dto->currencyCode,
                'transaction_date'   => $dto->transactionDate,
                'status'             => ItemStatus::Pending->value,
                'match_confidence'   => 0,
                'metadata'           => $dto->metadata,
            ]);
        }
    }
}
