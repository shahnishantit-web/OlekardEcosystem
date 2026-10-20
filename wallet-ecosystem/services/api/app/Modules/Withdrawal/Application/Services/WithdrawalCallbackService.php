<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Application\Services;

use App\Modules\Fees\Domain\Contracts\FeesModuleContract;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use App\Modules\Withdrawal\Domain\Contracts\PayoutProviderAdapter;
use App\Modules\Withdrawal\Domain\Enums\WithdrawalFailureReason;
use App\Modules\Withdrawal\Domain\Enums\WithdrawalStatus;
use App\Modules\Withdrawal\Domain\Events\WithdrawalAmountMismatch;
use App\Modules\Withdrawal\Domain\Events\WithdrawalCompleted;
use App\Modules\Withdrawal\Domain\Events\WithdrawalFailed;
use App\Modules\Withdrawal\Domain\Events\WithdrawalLateCallback;
use App\Modules\Withdrawal\Domain\Exceptions\InvalidCallbackSignatureException;
use App\Modules\Withdrawal\Infrastructure\Models\WithdrawalProviderCallbackModel;
use App\Modules\Withdrawal\Infrastructure\Models\WithdrawalTransactionModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class WithdrawalCallbackService
{
    public function __construct(
        private readonly PayoutProviderAdapter        $adapter,
        private readonly WalletModuleContract         $wallet,
        private readonly LedgerModuleContract         $ledger,
        private readonly WithdrawalReceiptService     $receiptService,
        private readonly WithdrawalMetricsService     $metrics,
        private readonly AuditServiceInterface        $audit,
        private readonly EventPublisherInterface      $events,
        private readonly ConfigurationServiceInterface $config,
        private readonly ?FeesModuleContract          $fees = null,
    ) {}

    /**
     * @throws InvalidCallbackSignatureException
     */
    public function processCallback(string $provider, array $rawPayload, string $signature): void
    {
        $startTime = microtime(true);

        $verified = $this->adapter->verifyCallback($rawPayload, $signature, $provider);
        $isValid  = $verified['valid'] ?? false;

        if (! $isValid) {
            // Persist invalid callback before throwing
            WithdrawalProviderCallbackModel::create([
                'id'                       => (string) Uuid::uuid7(),
                'withdrawal_transaction_id' => null,
                'provider'                 => $provider,
                'provider_reference_id'    => $verified['provider_reference_id'] ?? ($rawPayload['provider_reference_id'] ?? 'unknown'),
                'status'                   => $verified['status'] ?? 'unknown',
                'raw_payload'              => $rawPayload,
                'signature_valid'          => false,
                'received_at'              => now(),
            ]);

            throw new InvalidCallbackSignatureException($provider);
        }

        $providerRefId = $verified['provider_reference_id'] ?? null;
        $callbackStatus = $verified['status'] ?? 'unknown';
        $callbackAmount  = $verified['amount'] ?? null;

        // Resolve transaction by provider_reference_id
        $tx = WithdrawalTransactionModel::where('provider_reference_id', $providerRefId)->first();

        // Persist callback record (before processing for audit trail)
        $callback = WithdrawalProviderCallbackModel::create([
            'id'                       => (string) Uuid::uuid7(),
            'withdrawal_transaction_id' => $tx?->id,
            'provider'                 => $provider,
            'provider_reference_id'    => (string) $providerRefId,
            'status'                   => $callbackStatus,
            'raw_payload'              => $rawPayload,
            'signature_valid'          => true,
            'received_at'              => now(),
        ]);

        if ($tx === null) {
            return;
        }

        // Late callback idempotency check
        if ($tx->status === WithdrawalStatus::Completed) {
            $this->events->publish(new WithdrawalLateCallback(
                withdrawalTransactionId: $tx->id,
                walletId:                $tx->wallet_id,
                currencyCode:            $tx->pocket_currency_code,
                grossAmount:             $tx->gross_amount,
                channel:                 $tx->channel->value,
                providerReferenceId:     (string) $providerRefId,
                occurredAt:              now()->toIso8601String(),
                correlationId:           $tx->correlation_id,
            ));

            $callback->processed_at = now();
            $callback->save();
            return;
        }

        if ($tx->status !== WithdrawalStatus::Processing) {
            $callback->processed_at = now();
            $callback->save();
            return;
        }

        if ($callbackStatus === 'success') {
            $this->processSuccess($tx, $callback, $callbackAmount, $provider, $startTime);
        } else {
            $this->processFailure($tx, $callback, $provider, $startTime);
        }
    }

    private function processSuccess(
        WithdrawalTransactionModel    $tx,
        WithdrawalProviderCallbackModel $callback,
        ?int $callbackAmount,
        string $provider,
        float $startTime,
    ): void {
        $correlationId = $tx->correlation_id;

        // Amount mismatch check
        if ($callbackAmount !== null && $callbackAmount !== $tx->net_payout_amount) {
            DB::connection('platform')->transaction(function () use ($tx, $callbackAmount, $callback, $correlationId): void {
                $tx->refresh();
                if ($tx->status !== WithdrawalStatus::Processing) {
                    return;
                }

                if ($tx->wallet_hold_id !== null) {
                    $this->wallet->releaseHold($tx->wallet_hold_id, $correlationId);
                    $this->metrics->recordHoldReleased('amount_mismatch');
                }

                $tx->status         = WithdrawalStatus::Failed;
                $tx->failure_reason = WithdrawalFailureReason::AmountMismatch;
                $tx->failed_at      = now();
                $tx->save();

                $this->events->publish(new WithdrawalAmountMismatch(
                    withdrawalTransactionId: $tx->id,
                    walletId:                $tx->wallet_id,
                    currencyCode:            $tx->pocket_currency_code,
                    grossAmount:             $tx->gross_amount,
                    channel:                 $tx->channel->value,
                    expectedAmount:          $tx->net_payout_amount,
                    callbackAmount:          $callbackAmount,
                    occurredAt:              now()->toIso8601String(),
                    correlationId:           $correlationId,
                ));

                $callback->processed_at = now();
                $callback->save();
            });

            $elapsed = microtime(true) - $startTime;
            $this->metrics->recordCallbackDuration($provider, 'amount_mismatch', $elapsed);
            return;
        }

        // Post journal and complete atomically
        DB::connection('platform')->transaction(function () use ($tx, $callback, $correlationId): void {
            $tx->refresh();
            if ($tx->status !== WithdrawalStatus::Processing) {
                return;
            }

            // Build journal lines
            $customerPocketRef = $this->wallet->getPocketLedgerAccountRef($tx->wallet_id, $tx->pocket_currency_code);
            $payoutTransitRef  = (string) ($this->config->get("ledger.payout_transit.{$tx->pocket_currency_code}") ?? '');
            $feeRevenueRef     = (string) ($this->config->get("ledger.fee_revenue.{$tx->pocket_currency_code}") ?? '');

            $lines = [
                [
                    'ledger_account_reference' => $customerPocketRef,
                    'direction'                => 'debit',
                    'amount'                   => $tx->gross_amount,
                ],
                [
                    'ledger_account_reference' => $payoutTransitRef,
                    'direction'                => 'credit',
                    'amount'                   => $tx->net_payout_amount,
                ],
            ];

            if ($tx->fee_amount > 0) {
                $lines[] = [
                    'ledger_account_reference' => $feeRevenueRef,
                    'direction'                => 'credit',
                    'amount'                   => $tx->fee_amount,
                ];
            }

            $journalId = $this->ledger->postEntry([
                'idempotency_key'       => "withdrawal_complete:{$tx->id}",
                'entry_type'            => 'debit',
                'currency_code'         => $tx->pocket_currency_code,
                'transaction_reference' => $tx->transaction_reference,
                'correlation_id'        => $correlationId,
                'description'           => "Withdrawal payout — {$tx->transaction_reference}",
                'lines'                 => $lines,
            ]);

            // Release hold AFTER journal is posted
            if ($tx->wallet_hold_id !== null) {
                $this->wallet->releaseHold($tx->wallet_hold_id, $correlationId);
                $this->metrics->recordHoldReleased('completed');
            }

            $tx->status            = WithdrawalStatus::Completed;
            $tx->completed_at      = now();
            $tx->ledger_journal_id = $journalId;
            $tx->save();

            $this->receiptService->createReceipt($tx, $journalId, $tx->provider_reference_id);

            // E21 fee tracking — move fee from legacy fee_revenue account to E21 revenue tracking
            if ($this->fees !== null && $tx->fee_amount > 0) {
                $feeResult = $this->fees->calculateFee('withdrawal', $tx->gross_amount, $tx->pocket_currency_code, 'global', null);
                $this->fees->recordFee(
                    transactionId:   $tx->id,
                    amountMinor:     $tx->fee_amount,
                    feeRuleId:       $feeResult->feeRuleId,
                    currency:        $tx->pocket_currency_code,
                    debitAccountRef: $feeRevenueRef,
                    creditAccountRef:'revenue:fees:' . $tx->pocket_currency_code,
                    idempotencyKey:  Uuid::uuid5(Uuid::NAMESPACE_URL, $tx->id . ':fee')->toString(),
                    correlationId:   $correlationId,
                );
            }

            $this->events->publish(new WithdrawalCompleted(
                withdrawalTransactionId: $tx->id,
                walletId:                $tx->wallet_id,
                currencyCode:            $tx->pocket_currency_code,
                grossAmount:             $tx->gross_amount,
                feeAmount:               $tx->fee_amount,
                netPayoutAmount:         $tx->net_payout_amount,
                channel:                 $tx->channel->value,
                ledgerJournalId:         $journalId,
                occurredAt:              now()->toIso8601String(),
                correlationId:           $correlationId,
            ));

            $this->audit->record(new AuditEventData(
                action:        'withdrawal.transaction.completed',
                actorId:       $tx->initiated_by,
                actorType:     'CUSTOMER',
                resourceType:  'withdrawal_transaction',
                resourceId:    $tx->id,
                correlationId: $correlationId,
                outcome:       'success',
                afterState:    [
                    'status'            => WithdrawalStatus::Completed->value,
                    'ledger_journal_id' => $journalId,
                ],
            ));

            $callback->processed_at = now();
            $callback->save();
        });

        $elapsed = microtime(true) - $startTime;
        $this->metrics->recordCompleted($tx->channel->value);
        $this->metrics->recordCallbackDuration($provider, 'success', $elapsed);
    }

    private function processFailure(
        WithdrawalTransactionModel    $tx,
        WithdrawalProviderCallbackModel $callback,
        string $provider,
        float $startTime,
    ): void {
        $correlationId = $tx->correlation_id;

        DB::connection('platform')->transaction(function () use ($tx, $callback, $correlationId): void {
            $tx->refresh();
            if ($tx->status !== WithdrawalStatus::Processing) {
                return;
            }

            if ($tx->wallet_hold_id !== null) {
                $this->wallet->releaseHold($tx->wallet_hold_id, $correlationId);
                $this->metrics->recordHoldReleased('provider_declined');
            }

            $tx->status         = WithdrawalStatus::Failed;
            $tx->failure_reason = WithdrawalFailureReason::ProviderDeclined;
            $tx->failed_at      = now();
            $tx->save();

            $this->events->publish(new WithdrawalFailed(
                withdrawalTransactionId: $tx->id,
                walletId:                $tx->wallet_id,
                currencyCode:            $tx->pocket_currency_code,
                grossAmount:             $tx->gross_amount,
                channel:                 $tx->channel->value,
                failureReason:           WithdrawalFailureReason::ProviderDeclined->value,
                occurredAt:              now()->toIso8601String(),
                correlationId:           $correlationId,
            ));

            $this->audit->record(new AuditEventData(
                action:        'withdrawal.transaction.failed',
                actorId:       $tx->initiated_by,
                actorType:     'CUSTOMER',
                resourceType:  'withdrawal_transaction',
                resourceId:    $tx->id,
                correlationId: $correlationId,
                outcome:       'failure',
                afterState:    ['status' => WithdrawalStatus::Failed->value, 'failure_reason' => WithdrawalFailureReason::ProviderDeclined->value],
            ));

            $callback->processed_at = now();
            $callback->save();
        });

        $elapsed = microtime(true) - $startTime;
        $this->metrics->recordFailed($tx->channel->value, WithdrawalFailureReason::ProviderDeclined->value);
        $this->metrics->recordCallbackDuration($provider, 'failure', $elapsed);
    }
}
