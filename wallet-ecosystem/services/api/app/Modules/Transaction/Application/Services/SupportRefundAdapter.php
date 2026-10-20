<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Application\Services;

use App\Modules\Transaction\Domain\Contracts\SupportRefundContract;
use App\Modules\Transaction\Infrastructure\Models\TransactionProjectionModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class SupportRefundAdapter implements SupportRefundContract
{
    public function __construct(private readonly TransactionProjectionService $projections) {}

    public function original(string $id): ?array
    {
        if (DB::connection('platform')->transactionLevel() < 1) {
            throw new \LogicException('Refund lookup requires transaction');
        }
        $t = TransactionProjectionModel::lockForUpdate()->find($id);
        if (! $t) {
            return null;
        }
        $result = $t->only(['id', 'amount', 'currency_code', 'wallet_id', 'customer_id', 'status', 'direction', 'type']);
        $result['remaining_amount'] = (int) $t->amount - (int) TransactionProjectionModel::where('parent_transaction_id', $t->id)->where('type', 'refund')->where('status', 'completed')->sum('amount');

        return $result;
    }

    public function record(array $original, int $amount, string $journalId, string $disputeId, string $correlationId): string
    {
        $id = (string) Uuid::uuid7();
        $this->projections->upsert(['id' => $id, 'reference' => 'RFND-'.str_replace('-', '', $id), 'type' => 'refund', 'status' => 'completed', 'amount' => $amount, 'currency_code' => $original['currency_code'], 'direction' => 'credit', 'wallet_id' => $original['wallet_id'], 'customer_id' => $original['customer_id'], 'description' => 'Dispute refund', 'source_module' => 'support', 'metadata' => ['dispute_id' => $disputeId], 'idempotency_key' => 'dispute:'.$disputeId, 'correlation_id' => $correlationId, 'ledger_journal_id' => $journalId, 'parent_transaction_id' => $original['id'], 'completed_at' => now()], $id);

        return $id;
    }
}
