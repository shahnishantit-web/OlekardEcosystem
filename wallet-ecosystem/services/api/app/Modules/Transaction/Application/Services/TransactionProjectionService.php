<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Application\Services;

use App\Modules\Transaction\Infrastructure\Models\TransactionProjectionModel;
use App\Modules\Transaction\Infrastructure\Models\TransactionStatusHistoryModel;
use App\Modules\Transaction\Infrastructure\Repositories\TransactionProjectionRepository;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

final class TransactionProjectionService
{
    public function __construct(
        private readonly TransactionProjectionRepository $repository,
    ) {}

    public function upsert(array $attributes, string $transactionId): TransactionProjectionModel
    {
        // Sender and recipient are distinct views of one source transfer.
        if (preg_match('/^([0-9a-f-]{36})-(sender|recipient)$/i', $transactionId, $parts)) {
            $attributes['metadata']['source_transaction_id'] = $parts[1];
            $transactionId = Uuid::uuid5(Uuid::NAMESPACE_URL, $transactionId)->toString();
        }
        $existing = $this->repository->findById($transactionId);
        if ($existing !== null && in_array($existing->status, ['completed', 'failed', 'cancelled', 'refunded'], true)
            && in_array($attributes['status'] ?? '', ['initiated', 'pending', 'processing', 'pending_provider'], true)) {
            return $existing;
        }
        $previousStatus = $existing?->status;

        $projection = $this->repository->upsert($attributes, $transactionId);

        $newStatus = $attributes['status'] ?? null;
        if ($newStatus !== null && $newStatus !== $previousStatus) {
            $this->appendStatusHistory(
                transactionId: $transactionId,
                previousStatus: $previousStatus,
                newStatus: $newStatus,
                actorId: null,
                actorType: 'system',
                reason: null,
                occurredAt: $attributes['updated_at'] ?? now()->toIso8601String(),
            );
        }

        Log::info('transaction.projection.upserted', [
            'transaction_id' => $transactionId,
            'type'           => $attributes['type'] ?? null,
            'status'         => $newStatus,
            'source_module'  => $attributes['source_module'] ?? null,
        ]);

        return $projection;
    }

    public function appendStatusHistory(
        string $transactionId,
        ?string $previousStatus,
        string $newStatus,
        ?string $actorId,
        ?string $actorType,
        ?string $reason,
        string $occurredAt,
    ): void {
        TransactionStatusHistoryModel::create([
            'id'              => (string) Uuid::uuid7(),
            'transaction_id'  => $transactionId,
            'previous_status' => $previousStatus,
            'new_status'      => $newStatus,
            'actor_id'        => $actorId,
            'actor_type'      => $actorType,
            'reason'          => $reason,
            'occurred_at'     => $occurredAt,
        ]);
    }
}
