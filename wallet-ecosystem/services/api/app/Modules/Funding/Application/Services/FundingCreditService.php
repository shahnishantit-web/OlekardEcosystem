<?php

declare(strict_types=1);

namespace App\Modules\Funding\Application\Services;

use App\Modules\Fees\Domain\Contracts\FeesModuleContract;
use App\Modules\Funding\Domain\Enums\FundingFailureReason;
use App\Modules\Funding\Domain\Enums\FundingStatus;
use App\Modules\Funding\Domain\Events\FundingTransactionCompleted;
use App\Modules\Funding\Domain\Events\FundingTransactionFailed;
use App\Modules\Funding\Domain\Exceptions\FundingTransactionNotFoundException;
use App\Modules\Funding\Infrastructure\Models\FundingReceiptModel;
use App\Modules\Funding\Infrastructure\Models\FundingTransactionModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Posts the double-entry credit to the wallet pocket when a funding transaction completes.
 *
 * Journal (digital top-up — FR-E08-031):
 *   DEBIT  platform float account      (net_amount)
 *   CREDIT customer pocket account     (net_amount)
 *
 * Non-negotiable:
 *   - No direct balance mutation — all credits via LedgerModuleContract::postEntry()
 *   - Idempotency namespace: funding_callback:{provider_reference_id}
 *   - A FundingReceipt is written atomically with the journal (immutable after insert)
 *   - Emits FundingTransactionCompleted event and audit record
 */
final class FundingCreditService
{
    public function __construct(
        private readonly LedgerModuleContract         $ledger,
        private readonly WalletModuleContract         $wallet,
        private readonly ConfigurationServiceInterface $config,
        private readonly AuditServiceInterface        $audit,
        private readonly EventPublisherInterface      $events,
        private readonly MetricsCollectorInterface    $metrics,
        private readonly ?FeesModuleContract          $fees = null,
    ) {}

    /**
     * Credit the wallet and complete the transaction.
     *
     * @param array{
     *   transaction_id:         string,
     *   provider_reference_id:  string,
     *   correlation_id:         string,
     * } $request
     *
     * @throws FundingTransactionNotFoundException
     * @throws \App\Modules\Funding\Domain\Exceptions\FloatAccountNotConfiguredException
     */
    public function credit(array $request): FundingTransactionModel
    {
        return DB::connection('platform')->transaction(fn () => $this->creditWithinTransaction($request));
    }

    private function creditWithinTransaction(array $request): FundingTransactionModel
    {
        $transactionId       = $request['transaction_id'];
        $providerReferenceId = $request['provider_reference_id'];
        $correlationId       = $request['correlation_id'];

        $tx = FundingTransactionModel::lockForUpdate()->find($transactionId);
        if ($tx === null) {
            throw FundingTransactionNotFoundException::forId($transactionId);
        }
        if ($tx->status === FundingStatus::Completed) return $tx;

        $pocketRef   = $this->wallet->getPocketLedgerAccountRef($tx->wallet_id, $tx->pocket_currency_code);
        $floatRefKey = 'ledger.float_account.' . $tx->pocket_currency_code;
        $floatRef    = $request['float_account_reference'] ?? $this->config->get($floatRefKey);

        if ($floatRef === null) {
            throw new \App\Modules\Funding\Domain\Exceptions\FloatAccountNotConfiguredException(
                $tx->pocket_currency_code
            );
        }

        if ($pocketRef === null) {
            throw new \DomainException(
                "No [{$tx->pocket_currency_code}] pocket ledger account found for wallet [{$tx->wallet_id}]."
            );
        }

        $tx = DB::transaction(function () use ($tx, $pocketRef, $floatRef, $providerReferenceId, $correlationId): FundingTransactionModel {
            $tx->refresh();

            if ($tx->status === FundingStatus::Completed) {
                return $tx; // idempotent replay
            }

            // Post double-entry journal (FR-E08-031)
            $idempotencyKey = 'funding_callback:' . $providerReferenceId;
            $journalId = $this->ledger->postEntry([
                'idempotency_key'       => $idempotencyKey,
                'entry_type'            => 'credit',
                'currency_code'         => $tx->pocket_currency_code,
                'transaction_reference' => $tx->transaction_reference,
                'correlation_id'        => $correlationId,
                'description'           => "Wallet funding — {$tx->funding_method->value}",
                'lines'                 => [
                    ['ledger_account_reference' => $floatRef,   'direction' => 'debit', 'amount' => $tx->net_amount],
                    ['ledger_account_reference' => $pocketRef,  'direction' => 'credit',  'amount' => $tx->net_amount],
                ],
            ]);

            // E21 fee tracking — the fee stays in float (not explicitly journaled by E08);
            // debit float (where excess sits) and credit revenue:fees to track it.
            if ($this->fees !== null && property_exists($tx, 'fee_amount') && $tx->fee_amount > 0) {
                $feeResult = $this->fees->calculateFee('funding', $tx->net_amount, $tx->pocket_currency_code, 'global', null);
                $this->fees->recordFee(
                    transactionId:   $tx->id,
                    amountMinor:     $tx->fee_amount,
                    feeRuleId:       $feeResult->feeRuleId,
                    currency:        $tx->pocket_currency_code,
                    debitAccountRef: $floatRef,
                    creditAccountRef:'revenue:fees:' . $tx->pocket_currency_code,
                    idempotencyKey:  Uuid::uuid5(Uuid::NAMESPACE_URL, $tx->id . ':fee')->toString(),
                    correlationId:   $correlationId,
                );
            }

            // Write immutable receipt
            $receipt = new FundingReceiptModel();
            $receipt->id                     = (string) Uuid::uuid7();
            $receipt->funding_transaction_id = $tx->id;
            $receipt->receipt_reference      = (string) Uuid::uuid7();
            $receipt->amount                 = $tx->gross_amount;
            $receipt->fee_amount             = $tx->fee_amount;
            $receipt->net_amount             = $tx->net_amount;
            $receipt->customer_id            = $tx->customer_id;
            $receipt->wallet_id              = $tx->wallet_id;
            $receipt->ledger_journal_id      = $journalId;
            $receipt->currency_code          = $tx->pocket_currency_code;
            $receipt->funding_method         = $tx->funding_method;
            $receipt->completed_at           = now();
            $receipt->save();

            $tx->status                = FundingStatus::Completed;
            $tx->ledger_journal_id     = $journalId;
            $tx->provider_reference_id = $providerReferenceId;
            $tx->completed_at          = now();
            $tx->save();

            return $tx;
        });

        $this->events->publish(new FundingTransactionCompleted(
            fundingTransactionId: $tx->id,
            walletId:             $tx->wallet_id,
            amount:               $tx->gross_amount,
            netAmount:            $tx->net_amount,
            ledgerJournalId:      $tx->ledger_journal_id,
            status:               $tx->status->value,
            occurredAt:           now()->toIso8601String(),
            currencyCode:         $tx->pocket_currency_code,
            correlationId:        $correlationId,
        ));

        $this->audit->record(new AuditEventData(
            action:       'funding.transaction.completed',
            actorType:    'SYSTEM',
            resourceType: 'funding_transaction',
            correlationId: $correlationId,
            outcome:      'success',
            resourceId:   $tx->id,
            afterState:   ['status' => FundingStatus::Completed->value, 'net_amount' => $tx->net_amount],
        ));

        $this->metrics->increment('funding.transaction.completed', [
            'method'   => $tx->funding_method->value,
            'currency' => $tx->pocket_currency_code,
        ]);

        return $tx;
    }
}
