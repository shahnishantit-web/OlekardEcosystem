<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Application\Services;

use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Transaction\Domain\Exceptions\TransactionNotFoundException;
use App\Modules\Transaction\Domain\Exceptions\TransactionNotReversibleException;
use App\Modules\Transaction\Infrastructure\Models\TransactionProjectionModel;
use App\Modules\Transaction\Infrastructure\Repositories\TransactionProjectionRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

final class RefundService
{
    public function __construct(
        private readonly TransactionProjectionRepository $transactionRepo,
        private readonly LedgerModuleContract            $ledger,
        private readonly TransactionProjectionService    $projectionService,
    ) {}

    public function initiate(
        string $transactionId,
        string $initiatedBy,
        string $idempotencyKey,
        ?string $reason,
        string $correlationId,
    ): TransactionProjectionModel {
        return DB::connection('platform')->transaction(function () use (
            $transactionId, $correlationId
        ): TransactionProjectionModel {
            $tx = $this->transactionRepo->findByIdForUpdate($transactionId);

            if ($tx === null) {
                throw TransactionNotFoundException::forId($transactionId);
            }

            if ($tx->status !== 'completed') {
                throw TransactionNotReversibleException::notCompleted($transactionId);
            }

            $template = $tx->metadata['reversal_journal_template'] ?? null;

            if ($template === null) {
                throw new \RuntimeException("Transaction [{$transactionId}] has no refund metadata.");
            }

            $refundId = (string) Uuid::uuid7();

            $journalId = $this->ledger->postEntry([
                'transaction_reference' => "REFUND-{$refundId}",
                'description'           => "Refund for transaction {$tx->reference}",
                'correlation_id'        => $correlationId,
                'lines'                 => [
                    [
                        'type'                    => 'DEBIT',
                        'ledger_account_reference' => $template['credit_account'] ?? 'system-suspense',
                        'amount'                   => $template['amount'],
                        'currency_code'            => $template['currency_code'],
                    ],
                    [
                        'type'                    => 'CREDIT',
                        'ledger_account_reference' => $template['debit_account'] ?? 'system-suspense',
                        'amount'                   => $template['amount'],
                        'currency_code'            => $template['currency_code'],
                    ],
                ],
            ]);

            $refundProjection = $this->projectionService->upsert([
                'id'                    => $refundId,
                'reference'             => 'REFUND-' . strtoupper(substr($refundId, 0, 8)),
                'type'                  => 'refund',
                'status'                => 'completed',
                'amount'                => $template['amount'],
                'currency_code'         => $template['currency_code'],
                'direction'             => $tx->direction->value === 'debit' ? 'credit' : 'debit',
                'wallet_id'             => $tx->wallet_id,
                'customer_id'           => $tx->customer_id,
                'description'           => "Refund for {$tx->reference}",
                'source_module'         => 'e11_transaction',
                'ledger_journal_id'     => $journalId,
                'parent_transaction_id' => $transactionId,
                'completed_at'          => now(),
                'correlation_id'        => $correlationId,
                'created_at'            => now(),
                'updated_at'            => now(),
            ], $refundId);

            Log::info('transaction.refund.initiated', [
                'refund_id'       => $refundId,
                'transaction_id'  => $transactionId,
                'journal_id'      => $journalId,
                'amount'          => $template['amount'],
                'currency_code'   => $template['currency_code'],
            ]);

            return $refundProjection;
        });
    }
}
