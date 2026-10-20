<?php

declare(strict_types=1);

namespace App\Modules\Qr\Application\Services;

use App\Modules\Fees\Domain\Contracts\FeesModuleContract;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Qr\Domain\Contracts\MerchantReadServiceInterface;
use App\Modules\Qr\Domain\DTO\ExecuteQrPaymentCommand;
use App\Modules\Qr\Domain\DTO\QrFeeQuoteResult;
use App\Modules\Qr\Domain\DTO\QrPaymentResult;
use App\Modules\Qr\Domain\DTO\QrResolutionResult;
use App\Modules\Qr\Domain\Enums\QrCodeStatus;
use App\Modules\Qr\Domain\Enums\QrCodeType;
use App\Modules\Qr\Domain\Enums\QrPaymentStatus;
use App\Modules\Qr\Domain\Events\QrPaymentCompletedEvent;
use App\Modules\Qr\Domain\Exceptions\AmountOutOfRangeException;
use App\Modules\Qr\Domain\Exceptions\StepUpRequiredException;
use App\Modules\Qr\Domain\Exceptions\InvalidQrPayloadException;
use App\Modules\Qr\Domain\Exceptions\MerchantNotActiveException;
use App\Modules\Qr\Domain\Exceptions\QrAlreadyUsedException;
use App\Modules\Qr\Domain\Exceptions\QrExpiredException;
use App\Modules\Qr\Domain\Exceptions\QrNotActiveException;
use App\Modules\Qr\Domain\Exceptions\QrPayloadMismatchException;
use App\Modules\Qr\Domain\Exceptions\QrReservedException;
use App\Modules\Qr\Infrastructure\Models\QrCodeModel;
use App\Modules\Qr\Infrastructure\Models\QrPaymentTransactionModel;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use App\Modules\Wallet\Domain\Exceptions\InsufficientBalanceException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

final class QrPaymentService
{
    public function __construct(
        private readonly QrPayloadSigner              $signer,
        private readonly MerchantReadServiceInterface $merchantRead,
        private readonly WalletModuleContract         $wallet,
        private readonly LedgerModuleContract         $ledger,
        private readonly ConfigurationServiceInterface $config,
        private readonly AuditServiceInterface        $audit,
        private readonly EventPublisherInterface      $events,
        private readonly QrReceiptService             $receiptService,
        private readonly ?FeesModuleContract          $fees = null,
    ) {}

    public function resolve(string $encodedPayload, string $customerId, string $correlationId): QrResolutionResult
    {
        try {
            $fields = $this->signer->verify($encodedPayload);
        } catch (InvalidQrPayloadException $e) {
            $this->audit->record(new AuditEventData(
                action:        'qr.payload.tampered',
                actorType:     'customer',
                resourceType:  'qr_payload',
                correlationId: $correlationId,
                outcome:       'failure',
                actorId:       $customerId,
            ));
            throw $e;
        }

        /** @var QrCodeModel|null $qrCode */
        $qrCode = QrCodeModel::where('nonce', $fields['nonce'] ?? '')->first();

        if ($qrCode === null) {
            throw new QrNotActiveException();
        }

        match ($qrCode->status) {
            QrCodeStatus::Expired  => throw new QrExpiredException(),
            QrCodeStatus::Used     => throw new QrAlreadyUsedException(),
            QrCodeStatus::Inactive => throw new QrNotActiveException(),
            default                => null,
        };

        if ($qrCode->type === QrCodeType::Dynamic && $qrCode->expires_at !== null && $qrCode->expires_at->isPast()) {
            throw new QrExpiredException();
        }

        if (! $this->merchantRead->isActive($qrCode->merchant_id)) {
            throw new MerchantNotActiveException();
        }

        $merchantDisplayName = $this->merchantRead->getDisplayName($qrCode->merchant_id);

        return new QrResolutionResult(
            qrCodeId:            $qrCode->id,
            type:                $qrCode->type,
            merchantId:          $qrCode->merchant_id,
            merchantDisplayName: $merchantDisplayName,
            merchantCategory:    null,
            currency:            $qrCode->currency,
            amount:              $qrCode->amount,
            amountRequired:      $qrCode->type === QrCodeType::Static,
            expiresAt:           $qrCode->expires_at,
        );
    }

    public function quote(string $qrCodeId, string $amountStr, string $currency, string $correlationId): QrFeeQuoteResult
    {
        /** @var QrCodeModel|null $qrCode */
        $qrCode = QrCodeModel::where('id', $qrCodeId)
            ->where('status', QrCodeStatus::Active->value)
            ->first();

        if ($qrCode === null) {
            throw new QrNotActiveException();
        }

        $amountMinor = (int) round((float) $amountStr * 100);
        $minAmount   = (int) ($this->config->get('qr.payment.min_amount') ?? 1);
        $maxAmount   = (int) ($this->config->get('qr.payment.max_amount') ?? 1_000_000_00);

        if ($amountMinor < $minAmount || $amountMinor > $maxAmount) {
            throw new AmountOutOfRangeException($minAmount, $maxAmount);
        }

        $fee = $this->calculateFee($amountMinor);

        return new QrFeeQuoteResult(
            amount:   $amountMinor,
            fee:      $fee,
            total:    $amountMinor + $fee,
            currency: $currency,
        );
    }

    public function confirm(ExecuteQrPaymentCommand $cmd): QrPaymentResult
    {
        return DB::connection('platform')->transaction(function () use ($cmd): QrPaymentResult {
            // Step 1 — Idempotency check
            /** @var QrPaymentTransactionModel|null $existing */
            $existing = QrPaymentTransactionModel::where('idempotency_key', $cmd->idempotencyKey)->first();
            if ($existing !== null) {
                return $this->mapTransactionToResult($existing);
            }

            // Step 2 — Create initiated transaction
            $tx           = new QrPaymentTransactionModel();
            $tx->id               = Str::orderedUuid()->toString();
            $tx->idempotency_key  = $cmd->idempotencyKey;
            $tx->correlation_id   = $cmd->correlationId;
            $tx->qr_code_id       = $cmd->qrCodeId;
            $tx->customer_wallet_id = $cmd->customerWalletId;
            $tx->merchant_wallet_id = null;
            $tx->amount           = 0;
            $tx->fee              = 0;
            $tx->currency         = $cmd->currency;
            $tx->status           = QrPaymentStatus::Initiated;
            $tx->step_up_verified = ! empty($cmd->stepUpToken);
            $tx->save();

            // Step 2a — Audit initiated
            $this->audit->record(new AuditEventData(
                action:        'qr.payment.initiated',
                actorType:     'customer',
                resourceType:  'qr_payment_transaction',
                correlationId: $cmd->correlationId,
                outcome:       'success',
                actorId:       $cmd->customerWalletId,
                resourceId:    $tx->id,
                afterState:    ['qr_code_id' => $cmd->qrCodeId, 'status' => QrPaymentStatus::Initiated->value],
            ));

            // Step 3 — Pessimistic lock on QR code
            /** @var QrCodeModel $qrCode */
            $qrCode = QrCodeModel::where('id', $cmd->qrCodeId)->lockForUpdate()->firstOrFail();

            // Step 4 — Stale reservation reset
            if ($qrCode->status === QrCodeStatus::Reserved
                && $qrCode->reserved_until !== null
                && $qrCode->reserved_until->isPast()
            ) {
                $qrCode->status                    = QrCodeStatus::Active;
                $qrCode->reserved_by_customer_id   = null;
                $qrCode->reserved_until            = null;
                $qrCode->save();
            }

            // Step 5 — Re-validate status
            try {
                match ($qrCode->status) {
                    QrCodeStatus::Expired  => throw new QrExpiredException(),
                    QrCodeStatus::Used     => throw new QrAlreadyUsedException(),
                    QrCodeStatus::Inactive => throw new QrNotActiveException(),
                    QrCodeStatus::Reserved => throw new QrReservedException(),
                    default                => null,
                };

                if ($qrCode->type === QrCodeType::Dynamic
                    && $qrCode->expires_at !== null
                    && $qrCode->expires_at->isPast()
                ) {
                    throw new QrExpiredException();
                }
            } catch (\Throwable $e) {
                $tx->status         = QrPaymentStatus::Failed;
                $tx->failure_reason = $e->getMessage();
                $tx->save();
                $this->auditPaymentFailed($tx, $cmd->correlationId, $e->getMessage());
                throw $e;
            }

            // Step 6 — Validate dynamic QR amount match
            if ($qrCode->type === QrCodeType::Dynamic && $qrCode->amount !== null) {
                $requestedAmount = (int) round((float) ($cmd->amount ?? '0') * 100);
                if ($requestedAmount !== $qrCode->amount) {
                    $tx->status         = QrPaymentStatus::Failed;
                    $tx->failure_reason = 'Amount mismatch';
                    $tx->save();
                    throw new QrPayloadMismatchException();
                }
            }

            // Step 7 — Reserve QR
            $reservationTtl = (int) ($this->config->get('qr.reservation_ttl_seconds') ?? 120);
            $qrCode->status                  = QrCodeStatus::Reserved;
            $qrCode->reserved_by_customer_id = $cmd->customerWalletId;
            $qrCode->reserved_until          = now()->addSeconds($reservationTtl);
            $qrCode->save();

            // Step 8 — Re-validate merchant wallet active
            if (! $this->merchantRead->isActive($qrCode->merchant_id)) {
                $qrCode->status                  = QrCodeStatus::Active;
                $qrCode->reserved_by_customer_id = null;
                $qrCode->reserved_until          = null;
                $qrCode->save();
                $tx->status         = QrPaymentStatus::Failed;
                $tx->failure_reason = 'Merchant not active';
                $tx->save();
                $this->auditPaymentFailed($tx, $cmd->correlationId, 'Merchant not active');
                throw new MerchantNotActiveException();
            }

            // Step 9 — Validate currency match
            if (! empty($qrCode->currency) && $qrCode->currency !== $cmd->currency) {
                $qrCode->status                  = QrCodeStatus::Active;
                $qrCode->reserved_by_customer_id = null;
                $qrCode->reserved_until          = null;
                $qrCode->save();
                $tx->status         = QrPaymentStatus::Failed;
                $tx->failure_reason = 'Currency mismatch';
                $tx->save();
                $this->auditPaymentFailed($tx, $cmd->correlationId, 'Currency mismatch');
                throw new QrPayloadMismatchException();
            }

            // Determine actual amount
            $amountMinor = $qrCode->type === QrCodeType::Dynamic && $qrCode->amount !== null
                ? $qrCode->amount
                : (int) round((float) ($cmd->amount ?? '0') * 100);

            // Step 10 — Step-up auth check
            $stepUpThreshold = (int) ($this->config->get('auth.step_up_threshold') ?? 1_000_000);
            if ($amountMinor >= $stepUpThreshold && empty($cmd->stepUpToken)) {
                $qrCode->status                  = QrCodeStatus::Active;
                $qrCode->reserved_by_customer_id = null;
                $qrCode->reserved_until          = null;
                $qrCode->save();
                $tx->status         = QrPaymentStatus::Failed;
                $tx->failure_reason = 'Step-up authentication required';
                $tx->save();
                $this->auditPaymentFailed($tx, $cmd->correlationId, 'Step-up required');
                throw new StepUpRequiredException();
            }

            // Step 11 — Check customer balance
            $fee           = $this->calculateFee($amountMinor);
            $totalDebited  = $amountMinor + $fee;

            $available = $this->wallet->availableBalance($cmd->customerWalletId, $cmd->currency) ?? 0;
            if ($available < $totalDebited) {
                $qrCode->status                  = QrCodeStatus::Active;
                $qrCode->reserved_by_customer_id = null;
                $qrCode->reserved_until          = null;
                $qrCode->save();
                $tx->status         = QrPaymentStatus::Failed;
                $tx->failure_reason = 'Insufficient balance';
                $tx->save();
                $this->auditPaymentFailed($tx, $cmd->correlationId, 'Insufficient balance');
                throw new InsufficientBalanceException($available, $totalDebited);
            }

            // Update transaction with resolved amounts and merchant wallet
            $tx->merchant_wallet_id = $qrCode->merchant_id;
            $tx->amount             = $amountMinor;
            $tx->fee                = $fee;
            $tx->save();

            // Step 12 — Post triple-leg journal
            $customerLedgerRef = $this->wallet->getPocketLedgerAccountRef($cmd->customerWalletId, $cmd->currency);
            $merchantLedgerRef = $this->wallet->getPocketLedgerAccountRef($qrCode->merchant_id, $cmd->currency);
            $feeLedgerRef      = (string) ($this->config->get('ledger.fee_wallet.BWP') ?? '');

            try {
                $journalId = $this->ledger->postEntry([
                    'idempotency_key'     => 'qr-pay-' . $tx->id,
                    'entry_type'          => 'qr_payment',
                    'currency_code'       => $cmd->currency,
                    'transaction_reference' => $tx->id,
                    'correlation_id'      => $cmd->correlationId,
                    'description'         => 'QR merchant payment',
                    'lines'               => [
                        [
                            'ledger_account_reference' => $customerLedgerRef,
                            'direction'                => 'debit',
                            'amount'                   => $totalDebited,
                        ],
                        [
                            'ledger_account_reference' => $merchantLedgerRef,
                            'direction'                => 'credit',
                            'amount'                   => $amountMinor,
                        ],
                        [
                            'ledger_account_reference' => $feeLedgerRef,
                            'direction'                => 'credit',
                            'amount'                   => $fee,
                        ],
                    ],
                ]);
            } catch (\Throwable $e) {
                $qrCode->status                  = QrCodeStatus::Active;
                $qrCode->reserved_by_customer_id = null;
                $qrCode->reserved_until          = null;
                $qrCode->save();
                $tx->status         = QrPaymentStatus::Failed;
                $tx->failure_reason = 'Journal post failed: ' . $e->getMessage();
                $tx->save();
                $this->auditPaymentFailed($tx, $cmd->correlationId, $e->getMessage());

                Log::error('QR payment journal failed', [
                    'tx_id'         => $tx->id,
                    'correlation_id' => $cmd->correlationId,
                    'error'         => $e->getMessage(),
                ]);
                throw $e;
            }

            // Step 13 — Complete transaction
            $tx->status     = QrPaymentStatus::Completed;
            $tx->journal_id = $journalId;
            $tx->save();

            // Step 14 — Update QR code status
            if ($qrCode->type === QrCodeType::Dynamic) {
                $qrCode->status = QrCodeStatus::Used;
            } else {
                $qrCode->status = QrCodeStatus::Active;
            }
            $qrCode->reserved_by_customer_id = null;
            $qrCode->reserved_until          = null;
            $qrCode->save();

            // E21 fee tracking — move fee from legacy fee wallet to E21 revenue tracking
            if ($this->fees !== null && $fee > 0) {
                $feeResult = $this->fees->calculateFee('qr_payment', $amountMinor, $cmd->currency, 'global', null);
                $this->fees->recordFee(
                    transactionId:   $tx->id,
                    amountMinor:     $fee,
                    feeRuleId:       $feeResult->feeRuleId,
                    currency:        $cmd->currency,
                    debitAccountRef: $feeLedgerRef,
                    creditAccountRef:'revenue:fees:' . $cmd->currency,
                    idempotencyKey:  Uuid::uuid5(Uuid::NAMESPACE_URL, $tx->id . ':fee')->toString(),
                    correlationId:   $cmd->correlationId,
                );
            }

            // Step 15 — Create receipt
            $merchantDisplayName = $this->merchantRead->getDisplayName($qrCode->merchant_id);
            $receipt             = $this->receiptService->createForTransaction($tx, $merchantDisplayName);

            // Step 16 — Audit completed
            $this->audit->record(new AuditEventData(
                action:        'qr.payment.completed',
                actorType:     'customer',
                resourceType:  'qr_payment_transaction',
                correlationId: $cmd->correlationId,
                outcome:       'success',
                actorId:       $cmd->customerWalletId,
                resourceId:    $tx->id,
                afterState:    ['status' => QrPaymentStatus::Completed->value, 'journal_id' => $journalId],
            ));

            // Step 17 — Publish event
            $this->events->publish(new QrPaymentCompletedEvent(
                merchantId:       $qrCode->merchant_id,
                customerId:       $cmd->customerWalletId,
                amount:           $amountMinor,
                fee:              $fee,
                currency:         $cmd->currency,
                paymentReference: $tx->id,
                receiptRef:       $receipt->receipt_ref,
                occurredAt:       now(),
            ));

            return $this->mapTransactionToResult($tx, $merchantDisplayName, $receipt->receipt_ref);
        });
    }

    private function mapTransactionToResult(
        QrPaymentTransactionModel $tx,
        ?string $merchantDisplayName = null,
        ?string $receiptRef = null,
    ): QrPaymentResult {
        if ($merchantDisplayName === null) {
            $merchantDisplayName = $this->merchantRead->getDisplayName($tx->merchant_wallet_id);
        }

        if ($receiptRef === null) {
            $receipt    = $tx->receipt;
            $receiptRef = $receipt?->receipt_ref;
        }

        return new QrPaymentResult(
            id:                  $tx->id,
            status:              $tx->status,
            amount:              $tx->amount,
            fee:                 $tx->fee,
            totalDebited:        $tx->amount + $tx->fee,
            currency:            $tx->currency,
            merchantDisplayName: $merchantDisplayName,
            receiptRef:          $receiptRef,
            createdAt:           $tx->created_at,
        );
    }

    private function auditPaymentFailed(
        QrPaymentTransactionModel $tx,
        string $correlationId,
        string $reason,
    ): void {
        $this->audit->record(new AuditEventData(
            action:        'qr.payment.failed',
            actorType:     'customer',
            resourceType:  'qr_payment_transaction',
            correlationId: $correlationId,
            outcome:       'failure',
            actorId:       $tx->customer_wallet_id,
            resourceId:    $tx->id,
            afterState:    ['status' => QrPaymentStatus::Failed->value, 'failure_reason' => $reason],
        ));
    }

    private function calculateFee(int $amountMinor): int
    {
        $feeType  = (string) ($this->config->get('qr.payment.fee_type') ?? 'flat');
        $feeValue = (int) ($this->config->get('qr.payment.fee_value') ?? 0);

        if ($feeType === 'percentage') {
            return (int) round($amountMinor * $feeValue / 10000);
        }

        return $feeValue;
    }
}
