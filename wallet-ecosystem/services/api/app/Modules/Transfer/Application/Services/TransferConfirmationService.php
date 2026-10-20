<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Application\Services;

use App\Modules\Fees\Domain\Contracts\FeesModuleContract;
use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Transfer\Domain\Enums\TransferFailureReason;
use App\Modules\Transfer\Domain\Enums\TransferStatus;
use App\Modules\Transfer\Domain\Events\TransferCompleted;
use App\Modules\Transfer\Domain\Events\TransferFailed;
use App\Modules\Transfer\Domain\Exceptions\FeeMismatchException;
use App\Modules\Transfer\Domain\Exceptions\InvalidPinException;
use App\Modules\Transfer\Domain\Exceptions\TransferNotModifiableException;
use App\Modules\Transfer\Infrastructure\Models\TransferReceiptModel;
use App\Modules\Transfer\Infrastructure\Models\TransferTransactionModel;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class TransferConfirmationService
{
    public function __construct(
        private readonly IdentityModuleContract       $identity,
        private readonly LedgerModuleContract         $ledger,
        private readonly WalletModuleContract         $wallet,
        private readonly TransferFeeService           $feeService,
        private readonly TransferLimitService         $limitService,
        private readonly AuditServiceInterface        $audit,
        private readonly EventPublisherInterface      $events,
        private readonly ConfigurationServiceInterface $config,
        private readonly TransferMetricsService       $metrics,
        private readonly ?FeesModuleContract          $fees = null,
    ) {}

    /**
     * @throws TransferNotModifiableException
     * @throws FeeMismatchException
     * @throws InvalidPinException (Transfer domain exception — does NOT transition transfer to failed)
     * @throws \App\Modules\Transfer\Domain\Exceptions\InsufficientBalanceException
     */
    public function execute(
        string $transferId,
        string $customerId,
        string $pin,
        string $idempotencyKey,
        string $correlationId,
    ): TransferTransactionModel {
        return DB::connection('platform')->transaction(fn () => $this->executeWithinTransaction(
            $transferId, $customerId, $pin, $idempotencyKey, $correlationId,
        ));
    }

    private function executeWithinTransaction(
        string $transferId,
        string $customerId,
        string $pin,
        string $idempotencyKey,
        string $correlationId,
    ): TransferTransactionModel {
        $tx = TransferTransactionModel::whereKey($transferId)->lockForUpdate()->first();

        if ($tx === null || $tx->sender_customer_id !== $customerId) {
            throw new \DomainException("Transfer [{$transferId}] not found.");
        }

        // Idempotency: status is the durable guard; if already completed, return existing
        if ($tx->status === TransferStatus::Completed) {
            $tx->load('receipt');
            return $tx;
        }

        if (! $tx->status->isConfirmable()) {
            throw TransferNotModifiableException::forId($transferId, $tx->status->value);
        }

        // Fee re-validation (FR-E09-026 step 1)
        $currentRateBps = $this->feeService->getCurrentRateBps($tx->currency_code);
        if ($currentRateBps !== $tx->fee_rate_bps) {
            $newFees = $this->feeService->calculate($tx->gross_amount, $currentRateBps);
            throw new FeeMismatchException(
                newFeeAmount:   $newFees['fee_amount'],
                newNetAmount:   $newFees['net_amount'],
                newFeeRateBps:  $currentRateBps,
            );
        }

        // Daily limit re-validation excluding this transfer's own pending record (FR-E09-026 step 2)
        $this->limitService->validateDailyLimit(
            $tx->sender_customer_id,
            $tx->gross_amount,
            $tx->currency_code,
            excludeTransferId: $transferId,
        );

        // PIN verification — failure does NOT transition to failed
        $requireStepUp = (bool) $this->config->getOrDefault('transfer.require_step_up_pin', false);
        $pinValid      = $this->identity->verifyTransactionPin($customerId, $pin, $requireStepUp);

        if (! $pinValid) {
            $this->audit->record(new AuditEventData(
                action:        'transfer.confirm.pin_failed',
                actorId:       $customerId,
                actorType:     'CUSTOMER',
                resourceType:  'transfer_transaction',
                resourceId:    $transferId,
                correlationId: $correlationId,
                outcome:       'failure',
            ));

            throw new InvalidPinException();
        }

        // Recipient wallet status re-check
        if (! $this->wallet->isActive($tx->recipient_wallet_id)) {
            DB::transaction(function () use ($tx, $correlationId): void {
                $tx->refresh();
                if ($tx->status->isTerminal()) {
                    return;
                }
                $tx->status         = TransferStatus::Failed;
                $tx->failure_reason = TransferFailureReason::RecipientWalletSuspended;
                $tx->save();

                $this->events->publish(new TransferFailed(
                    transferId:     $tx->id,
                    senderWalletId: $tx->sender_wallet_id,
                    currencyCode:   $tx->currency_code,
                    grossAmount:    $tx->gross_amount,
                    failureReason:  TransferFailureReason::RecipientWalletSuspended->value,
                    occurredAt:     now()->toIso8601String(),
                    correlationId:  $correlationId,
                ));
            });

            throw TransferNotModifiableException::forId($transferId, TransferStatus::Failed->value);
        }

        // Atomic financial transaction
        try {
            DB::transaction(function () use ($tx, $idempotencyKey, $correlationId): void {
                $tx->refresh();

                if (! $tx->status->isConfirmable()) {
                    throw TransferNotModifiableException::forId($tx->id, $tx->status->value);
                }

                // Balance check with SELECT FOR UPDATE (inside LedgerModuleContract)
                $this->limitService->validateBalance(
                    $tx->gross_amount,
                    $this->wallet->availableBalance($tx->sender_wallet_id, $tx->currency_code) ?? 0,
                );

                // Build journal lines
                $lines = [
                    [
                        'ledger_account_reference' => $tx->sender_pocket_ledger_ref,
                        'direction'                => 'debit',
                        'amount'                   => $tx->gross_amount,
                    ],
                    [
                        'ledger_account_reference' => $tx->recipient_pocket_ledger_ref,
                        'direction'                => 'credit',
                        'amount'                   => $tx->net_amount,
                    ],
                ];

                if ($tx->fee_amount > 0) {
                    $feeAccountRef = (string) $this->config->getOrDefault(
                        'transfer.fee_account.' . $tx->currency_code, ''
                    );

                    $lines[] = [
                        'ledger_account_reference' => $feeAccountRef,
                        'direction'                => 'credit',
                        'amount'                   => $tx->fee_amount,
                    ];
                }

                $journalId = $this->ledger->postEntry([
                    'idempotency_key'       => 'transfer_confirm:' . $tx->id,
                    'entry_type'            => 'debit',
                    'currency_code'         => $tx->currency_code,
                    'transaction_reference' => $tx->transfer_reference,
                    'correlation_id'        => $tx->correlation_id,
                    'description'           => "Wallet transfer — {$tx->transfer_reference}",
                    'lines'                 => $lines,
                ]);

                $tx->status            = TransferStatus::Completed;
                $tx->completed_at      = now();
                $tx->ledger_journal_id = $journalId;
                $tx->save();

                // E21 fee tracking — move fee from intermediate fee account to E21 revenue account
                if ($this->fees !== null && $tx->fee_amount > 0) {
                    $feeAccountRef = (string) $this->config->getOrDefault(
                        'transfer.fee_account.' . $tx->currency_code, ''
                    );
                    $feeResult = $this->fees->calculateFee('transfer', $tx->gross_amount, $tx->currency_code, 'global', null);
                    $this->fees->recordFee(
                        transactionId:   $tx->id,
                        amountMinor:     $tx->fee_amount,
                        feeRuleId:       $feeResult->feeRuleId,
                        currency:        $tx->currency_code,
                        debitAccountRef: $feeAccountRef,
                        creditAccountRef:'revenue:fees:' . $tx->currency_code,
                        idempotencyKey:  Uuid::uuid5(Uuid::NAMESPACE_URL, $tx->id . ':fee')->toString(),
                        correlationId:   $tx->correlation_id,
                    );
                }

                // Immutable receipt
                $receipt = new TransferReceiptModel();
                $receipt->id                      = (string) Uuid::uuid7();
                $receipt->transfer_transaction_id = $tx->id;
                $receipt->receipt_reference       = (string) Uuid::uuid7();
                $receipt->sender_customer_id      = $tx->sender_customer_id;
                $receipt->recipient_customer_id   = $tx->recipient_customer_id;
                $receipt->currency_code           = $tx->currency_code;
                $receipt->gross_amount            = $tx->gross_amount;
                $receipt->fee_amount              = $tx->fee_amount;
                $receipt->net_amount              = $tx->net_amount;
                $receipt->issued_at               = now();
                $receipt->save();

                $tx->setRelation('receipt', $receipt);
            });
        } catch (TransferNotModifiableException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Journal post failure or balance failure — transition to failed (FR-E09-033)
            DB::transaction(function () use ($tx, $correlationId): void {
                $tx->refresh();
                if ($tx->status->isTerminal()) {
                    return;
                }
                $tx->status         = TransferStatus::Failed;
                $tx->failure_reason = TransferFailureReason::SystemError;
                $tx->save();

                $this->events->publish(new TransferFailed(
                    transferId:     $tx->id,
                    senderWalletId: $tx->sender_wallet_id,
                    currencyCode:   $tx->currency_code,
                    grossAmount:    $tx->gross_amount,
                    failureReason:  TransferFailureReason::SystemError->value,
                    occurredAt:     now()->toIso8601String(),
                    correlationId:  $correlationId,
                ));
            });

            throw $e;
        }

        $this->events->publish(new TransferCompleted(
            transferId:        $tx->id,
            senderWalletId:    $tx->sender_wallet_id,
            recipientWalletId: $tx->recipient_wallet_id,
            currencyCode:      $tx->currency_code,
            grossAmount:       $tx->gross_amount,
            feeAmount:         $tx->fee_amount,
            netAmount:         $tx->net_amount,
            receiptReference:  $tx->receipt->receipt_reference,
            occurredAt:        now()->toIso8601String(),
            correlationId:     $correlationId,
        ));

        $this->audit->record(new AuditEventData(
            action:        'transfer.confirmed',
            actorId:       $customerId,
            actorType:     'CUSTOMER',
            resourceType:  'transfer_transaction',
            resourceId:    $tx->id,
            correlationId: $correlationId,
            outcome:       'success',
            afterState:    [
                'status'           => TransferStatus::Completed->value,
                'ledger_journal_id' => $tx->ledger_journal_id,
            ],
        ));

        $this->metrics->transferCompleted($tx->currency_code, $tx->fee_amount);

        return $tx->fresh(['receipt']);
    }
}
