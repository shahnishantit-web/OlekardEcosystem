<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Application\Services;

use App\Modules\MultiCurrency\Application\DTOs\FxQuoteConfirmDTO;
use App\Modules\MultiCurrency\Domain\Contracts\CurrencyWalletRepositoryInterface;
use App\Modules\MultiCurrency\Domain\Contracts\FxQuoteRepositoryInterface;
use App\Modules\MultiCurrency\Domain\Contracts\FxTransactionRepositoryInterface;
use App\Modules\MultiCurrency\Domain\Enums\FxQuoteStatus;
use App\Modules\MultiCurrency\Domain\Enums\FxTransactionStatus;
use App\Modules\MultiCurrency\Domain\Events\FxConversionCompletedEvent;
use App\Modules\MultiCurrency\Domain\Events\FxConversionFailedEvent;
use App\Modules\MultiCurrency\Domain\Exceptions\FxQuoteExpiredException;
use App\Modules\MultiCurrency\Domain\Exceptions\FxQuoteNotFoundException;
use App\Modules\MultiCurrency\Domain\Exceptions\InsufficientBalanceException;
use App\Modules\MultiCurrency\Infrastructure\Models\FxTransactionModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

final class FxConversionService
{
    public function __construct(
        private readonly FxQuoteRepositoryInterface        $fxQuotes,
        private readonly FxTransactionRepositoryInterface  $fxTransactions,
        private readonly CurrencyWalletRepositoryInterface $currencyWallets,
        private readonly LedgerModuleContract              $ledger,
        private readonly EventPublisherInterface           $events,
        private readonly AuditServiceInterface             $audit,
        private readonly ConfigurationServiceInterface     $config,
    ) {}

    /**
     * Phase 2: Confirm a locked FX quote, post ledger entries, and return a completed FxTransaction.
     * Implements US-MCW-007 (Phase 2) and US-MCW-008 (FX ledger entries) atomically.
     */
    public function confirm(FxQuoteConfirmDTO $dto): FxTransactionModel
    {
        // Idempotency: return existing FxTransaction if already confirmed
        $existing = $this->fxTransactions->findByIdempotencyKey($dto->idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }

        $quote = $this->fxQuotes->findById($dto->quoteId);
        if ($quote === null || $quote->customer_id !== $dto->customerId) {
            throw new FxQuoteNotFoundException($dto->quoteId);
        }

        // Lazy expiry: mark as expired if TTL has elapsed
        if ($quote->status === FxQuoteStatus::Quoted && $quote->expires_at->isPast()) {
            $quote->status = FxQuoteStatus::Expired;
            $this->fxQuotes->save($quote);
        }

        if ($quote->status !== FxQuoteStatus::Quoted) {
            if ($quote->status === FxQuoteStatus::Expired) {
                throw new FxQuoteExpiredException($dto->quoteId);
            }
            throw new \DomainException("FX quote [{$dto->quoteId}] has already been {$quote->status->value} and cannot be confirmed.");
        }

        // Resolve FX suspense and revenue ledger account references from configuration
        $fxSuspenseRef = (string) $this->config->getOrDefault('multi_currency.fx_suspense_account_ref', '');
        $fxRevenueRef  = (string) $this->config->getOrDefault('multi_currency.fx_revenue_account_ref', '');

        if (empty($fxSuspenseRef) || empty($fxRevenueRef)) {
            throw new \RuntimeException('FX suspense/revenue account references are not configured. Check multi_currency.fx_suspense_account_ref and multi_currency.fx_revenue_account_ref.');
        }

        // Resolve customer's source and destination wallets
        $sourceWallet = $this->currencyWallets->findByCustomerAndCurrency($dto->customerId, $quote->from_currency_code);
        $destWallet   = $this->currencyWallets->findByCustomerAndCurrency($dto->customerId, $quote->to_currency_code);

        if ($sourceWallet === null) {
            throw new \DomainException("No [{$quote->from_currency_code}] wallet found for this customer.");
        }
        if ($destWallet === null) {
            throw new \DomainException("No [{$quote->to_currency_code}] wallet found for this customer.");
        }

        // Convert amounts to minor units for ledger posting
        $fromAmountMinor = (int) bcmul($quote->from_amount, '100', 0);
        $feeAmountMinor  = (int) bcmul($quote->fee_amount, '100', 0);
        $toAmountMinor   = (int) bcmul($quote->to_amount, '100', 0);
        // Total debited from source = from_amount (to_amount goes to dest, fee_amount goes to revenue)
        $totalDebitMinor = $toAmountMinor + $feeAmountMinor;

        try {
            return DB::connection('platform')->transaction(function () use (
                $dto, $quote, $sourceWallet, $destWallet,
                $fxSuspenseRef, $fxRevenueRef,
                $fromAmountMinor, $feeAmountMinor, $toAmountMinor
            ): FxTransactionModel {
                // Lock source wallet to prevent double-spend
                $lockedSource = $this->currencyWallets->lockForUpdate($sourceWallet->id);
                if ($lockedSource === null) {
                    throw new \DomainException("Source currency wallet not found for lock.");
                }
                $available    = $this->ledger->getBalance($lockedSource->pocket_ledger_account_reference);

                if ($available < $fromAmountMinor) {
                    throw new InsufficientBalanceException();
                }

                // --- US-MCW-008: Post 3-entry FX journal (suspense pattern) ---
                // Entry 1: Dr source wallet (full from_amount), Cr fx_suspense
                $this->ledger->postEntry([
                    'idempotency_key'       => 'fx_debit:' . $dto->idempotencyKey,
                    'entry_type'            => 'debit',
                    'currency_code'         => $quote->from_currency_code,
                    'transaction_reference' => $dto->idempotencyKey . ':debit',
                    'correlation_id'        => $dto->correlationId,
                    'description'           => "FX conversion debit [{$quote->from_currency_code}→{$quote->to_currency_code}]",
                    'lines'                 => [
                        ['ledger_account_reference' => $lockedSource->pocket_ledger_account_reference, 'direction' => 'debit',  'amount' => $fromAmountMinor],
                        ['ledger_account_reference' => $fxSuspenseRef,                                 'direction' => 'credit', 'amount' => $fromAmountMinor],
                    ],
                ]);

                // Entry 2: Dr fx_suspense (to_amount), Cr destination wallet
                $this->ledger->postEntry([
                    'idempotency_key'       => 'fx_credit:' . $dto->idempotencyKey,
                    'entry_type'            => 'credit',
                    'currency_code'         => $quote->to_currency_code,
                    'transaction_reference' => $dto->idempotencyKey . ':credit',
                    'correlation_id'        => $dto->correlationId,
                    'description'           => "FX conversion credit [{$quote->from_currency_code}→{$quote->to_currency_code}]",
                    'lines'                 => [
                        ['ledger_account_reference' => $fxSuspenseRef,                                  'direction' => 'debit',  'amount' => $toAmountMinor],
                        ['ledger_account_reference' => $destWallet->pocket_ledger_account_reference,    'direction' => 'credit', 'amount' => $toAmountMinor],
                    ],
                ]);

                // Entry 3: Dr fx_suspense (fee_amount), Cr platform.fx_revenue — only if fee > 0
                if ($feeAmountMinor > 0) {
                    $this->ledger->postEntry([
                        'idempotency_key'       => 'fx_fee:' . $dto->idempotencyKey,
                        'entry_type'            => 'fee',
                        'currency_code'         => $quote->from_currency_code,
                        'transaction_reference' => $dto->idempotencyKey . ':fee',
                        'correlation_id'        => $dto->correlationId,
                        'description'           => "FX conversion fee [{$quote->from_currency_code}→{$quote->to_currency_code}]",
                        'lines'                 => [
                            ['ledger_account_reference' => $fxSuspenseRef,  'direction' => 'debit',  'amount' => $feeAmountMinor],
                            ['ledger_account_reference' => $fxRevenueRef,   'direction' => 'credit', 'amount' => $feeAmountMinor],
                        ],
                    ]);
                }

                // Create FxTransaction record (append-only, never updated)
                $fxTx                  = new FxTransactionModel();
                $fxTx->id              = (string) Uuid::uuid7();
                $fxTx->customer_id     = $dto->customerId;
                $fxTx->quote_id        = $quote->id;
                $fxTx->from_wallet_id  = $sourceWallet->wallet_id;
                $fxTx->to_wallet_id    = $destWallet->wallet_id;
                $fxTx->from_currency_code = $quote->from_currency_code;
                $fxTx->to_currency_code   = $quote->to_currency_code;
                $fxTx->from_amount     = $quote->from_amount;
                $fxTx->to_amount       = $quote->to_amount;
                $fxTx->fee_amount      = $quote->fee_amount;
                $fxTx->rate            = $quote->rate;
                $fxTx->status          = FxTransactionStatus::Completed;
                $fxTx->idempotency_key = $dto->idempotencyKey;
                $fxTx->completed_at    = now();
                $this->fxTransactions->save($fxTx);

                // Update quote status to confirmed
                $quote->status            = FxQuoteStatus::Confirmed;
                $quote->fx_transaction_id = $fxTx->id;
                $this->fxQuotes->save($quote);

                $this->events->publish(new FxConversionCompletedEvent(
                    customerId:       $dto->customerId,
                    fxTransactionId:  $fxTx->id,
                    quoteId:          $quote->id,
                    fromCurrencyCode: $quote->from_currency_code,
                    toCurrencyCode:   $quote->to_currency_code,
                    fromAmount:       $quote->from_amount,
                    toAmount:         $quote->to_amount,
                    feeAmount:        $quote->fee_amount,
                    occurredAt:       now()->toIso8601String(),
                    correlationId:    $dto->correlationId,
                ));

                $this->audit->record(new AuditEventData(
                    action:        'multi_currency.fx_conversion.completed',
                    actorId:       $dto->customerId,
                    actorType:     'CUSTOMER',
                    resourceType:  'fx_transaction',
                    resourceId:    $fxTx->id,
                    correlationId: $dto->correlationId,
                    outcome:       'success',
                    afterState:    [
                        'fx_transaction_id' => $fxTx->id,
                        'from'              => $quote->from_currency_code,
                        'to'                => $quote->to_currency_code,
                        'from_amount'       => $quote->from_amount,
                        'to_amount'         => $quote->to_amount,
                    ],
                ));

                return $fxTx;
            });
        } catch (InsufficientBalanceException $e) {
            $this->recordFailure($dto, $quote->id ?? '', $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            Log::error('FX conversion failed', [
                'quote_id'       => $dto->quoteId,
                'customer_id'    => $dto->customerId,
                'error'          => $e->getMessage(),
                'correlation_id' => $dto->correlationId,
            ]);
            $this->recordFailure($dto, $dto->quoteId, $e->getMessage());
            throw $e;
        }
    }

    private function recordFailure(FxQuoteConfirmDTO $dto, string $quoteId, string $reason): void
    {
        try {
            DB::connection('platform')->transaction(function () use ($dto, $quoteId, $reason): void {
                $this->events->publish(new FxConversionFailedEvent(
                    customerId:    $dto->customerId,
                    quoteId:       $quoteId,
                    reason:        $reason,
                    occurredAt:    now()->toIso8601String(),
                    correlationId: $dto->correlationId,
                ));
            });
        } catch (\Throwable) {
            // Failure event publishing is best-effort
        }
    }
}
