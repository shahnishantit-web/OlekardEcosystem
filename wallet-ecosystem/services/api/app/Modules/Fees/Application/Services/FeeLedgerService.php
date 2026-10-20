<?php

declare(strict_types=1);

namespace App\Modules\Fees\Application\Services;

use App\Modules\Fees\Domain\Enums\FeeStatus;
use App\Modules\Fees\Domain\Events\FeePostedEvent;
use App\Modules\Fees\Domain\Events\FeeReversedEvent;
use App\Modules\Fees\Infrastructure\Models\FeeModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FeeLedgerService
{
    public function __construct(
        private readonly LedgerModuleContract   $ledger,
        private readonly EventPublisherInterface $events,
    ) {}

    /**
     * Record a fee and post the double-entry journal.
     * Idempotent on transaction_id: duplicate calls return the existing fee ID.
     * Returns '' when amountMinor is 0 (no fee to record).
     *
     * Callers derive $idempotencyKey as: Uuid::uuid5(Uuid::NAMESPACE_URL, $transactionId.':fee')
     */
    public function recordFee(
        string  $transactionId,
        int     $amountMinor,
        ?string $feeRuleId,
        string  $currency,
        string $debitAccountRef,
        string $creditAccountRef,
        string $idempotencyKey,
        string $correlationId,
    ): string {
        if ($amountMinor === 0) {
            return '';
        }

        $existing = FeeModel::where('transaction_id', $transactionId)->first();
        if ($existing !== null) {
            return $existing->id;
        }

        return DB::connection('platform')->transaction(function () use (
            $transactionId, $amountMinor, $feeRuleId, $currency,
            $debitAccountRef, $creditAccountRef, $idempotencyKey, $correlationId,
        ): string {
            $ledgerEntryId = $this->ledger->postEntry([
                'idempotency_key'       => $idempotencyKey,
                'entry_type'            => 'fee',
                'currency_code'         => $currency,
                'transaction_reference' => $transactionId,
                'correlation_id'        => $correlationId,
                'description'           => "Fee for transaction {$transactionId}",
                'lines'                 => [
                    ['ledger_account_reference' => $debitAccountRef,  'direction' => 'debit',  'amount' => $amountMinor],
                    ['ledger_account_reference' => $creditAccountRef, 'direction' => 'credit', 'amount' => $amountMinor],
                ],
            ]);

            $fee = FeeModel::create([
                'id'             => Str::orderedUuid()->toString(),
                'transaction_id' => $transactionId,
                'fee_rule_id'    => $feeRuleId,
                'amount_minor'   => $amountMinor,
                'currency'       => $currency,
                'status'         => FeeStatus::Posted->value,
                'ledger_entry_id'=> $ledgerEntryId,
            ]);

            $this->events->publish(new FeePostedEvent(
                feeId:         $fee->id,
                transactionId: $transactionId,
                amountMinor:   $amountMinor,
                currency:      $currency,
                ledgerEntryId: $ledgerEntryId,
                occurredAt:    now()->toIso8601String(),
                correlationId: $correlationId,
            ));

            return $fee->id;
        });
    }

    /**
     * Reverse a previously posted fee via a compensating double-entry journal.
     * Idempotent: already-reversed fees are a no-op.
     *
     * Callers derive $idempotencyKey as: Uuid::uuid5(Uuid::NAMESPACE_URL, $transactionId.':fee:reversal')
     */
    public function reverseFee(
        string $transactionId,
        string $debitAccountRef,
        string $creditAccountRef,
        string $idempotencyKey,
        string $correlationId,
    ): void {
        $fee = FeeModel::where('transaction_id', $transactionId)->first();

        if ($fee === null) {
            return;
        }

        if ($fee->status === FeeStatus::Reversed) {
            return;
        }

        DB::connection('platform')->transaction(function () use (
            $fee, $transactionId, $debitAccountRef, $creditAccountRef, $idempotencyKey, $correlationId,
        ): void {
            $ledgerEntryId = $this->ledger->postEntry([
                'idempotency_key'       => $idempotencyKey,
                'entry_type'            => 'fee',
                'currency_code'         => $fee->currency,
                'transaction_reference' => $transactionId . ':reversal',
                'correlation_id'        => $correlationId,
                'description'           => "Fee reversal for transaction {$transactionId}",
                'lines'                 => [
                    ['ledger_account_reference' => $debitAccountRef,  'direction' => 'debit',  'amount' => $fee->amount_minor],
                    ['ledger_account_reference' => $creditAccountRef, 'direction' => 'credit', 'amount' => $fee->amount_minor],
                ],
            ]);

            $fee->status                     = FeeStatus::Reversed;
            $fee->reversal_ledger_entry_id   = $ledgerEntryId;
            $fee->save();

            $this->events->publish(new FeeReversedEvent(
                feeId:                 $fee->id,
                transactionId:         $transactionId,
                reversalLedgerEntryId: $ledgerEntryId,
                occurredAt:            now()->toIso8601String(),
                correlationId:         $correlationId,
            ));
        });
    }
}
