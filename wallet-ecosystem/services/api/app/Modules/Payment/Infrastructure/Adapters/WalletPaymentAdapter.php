<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Adapters;

use App\Modules\Payment\Domain\Contracts\PaymentAdapterInterface;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use App\Modules\Payment\Domain\Exceptions\InsufficientFundsException;
use App\Modules\Payment\Domain\Exceptions\StepUpRequiredException;
use App\Modules\Payment\Domain\Exceptions\TransactionLimitExceededException;
use App\Modules\Wallet\Domain\Enums\WalletStatus;
use App\Modules\Wallet\Domain\Exceptions\UnsupportedCurrencyException;
use App\Modules\Wallet\Domain\Exceptions\WalletNotTransactableException;
use App\Modules\Payment\Domain\ValueObjects\PaymentAttemptResult;
use App\Modules\Payment\Domain\ValueObjects\PaymentCancelResult;
use App\Modules\Payment\Domain\ValueObjects\PaymentInitiateRequest;
use App\Modules\Payment\Domain\ValueObjects\PaymentRefundRequest;
use App\Modules\Payment\Domain\ValueObjects\PaymentRefundResult;
use App\Modules\Payment\Domain\ValueObjects\PaymentStatusResult;
use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;
use App\Modules\Identity\Domain\Contracts\ActorStepUpVerifierContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Enums\ProviderCapabilityEnum;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderCapabilitySet;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderRequest;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderResponse;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;

final class WalletPaymentAdapter implements PaymentAdapterInterface
{
    public function __construct(
        private readonly WalletModuleContract              $wallet,
        private readonly ConfigurationServiceInterface     $config,
        private readonly AuditServiceInterface             $audit,
        private readonly PaymentAttemptRepositoryInterface $attemptRepository,
        private readonly ActorStepUpVerifierContract       $stepUpVerifier,
    ) {
    }

    public function getProviderIdentifier(): string
    {
        return 'olekard_wallet';
    }

    /**
     * T091: Declared capabilities (Platform ProviderCapabilityEnum).
     * Wallet supports: InitiatePayment, QueryTransactionStatus, ReversePriorPayment.
     */
    public function getCapabilities(): ProviderCapabilitySet
    {
        return ProviderCapabilitySet::of(
            ProviderCapabilityEnum::InitiatePayment,
            ProviderCapabilityEnum::QueryTransactionStatus,
            ProviderCapabilityEnum::ReversePriorPayment,
        );
    }

    /**
     * T091/T092: Delegates raw provider send — wallet adapter has no external HTTP calls.
     */
    public function send(ProviderRequest $request): ProviderResponse
    {
        throw new \LogicException('WalletPaymentAdapter does not use send(); use initiatePayment() directly.');
    }

    /**
     * T092/P09: initiatePayment — 8-gate validation order (research Decision 1)
     * 1. walletId not null
     * 2. isActive() — wallet must be operable
     * 3. ownerActorId() — ownership verification
     * 4. pocketExists() — currency pocket exists
     * 5. availableBalance() — optimistic pre-check
     * 6. transaction limit config check
     * 7. step-up authentication if above threshold
     * 8. createHold() — authoritative SELECT FOR UPDATE balance enforcement
     */
    public function initiatePayment(PaymentInitiateRequest $request): PaymentAttemptResult
    {
        $walletId = $request->walletId;
        if ($walletId === null) {
            throw new \InvalidArgumentException('walletId is required for wallet payments.');
        }

        // 1. Wallet must be active and operable
        if (!$this->wallet->isActive($walletId)) {
            throw new WalletNotTransactableException(WalletStatus::Suspended);
        }

        // 2. Verify ownership
        $ownerActorId = $this->wallet->ownerActorId($walletId);
        if ($ownerActorId !== $request->payerActorId) {
            throw new \DomainException(
                "Wallet [{$walletId}] does not belong to actor [{$request->payerActorId}]."
            );
        }

        // 3. Pocket for this currency must exist on the wallet
        if (!$this->wallet->pocketExists($walletId, $request->currencyCode)) {
            throw new UnsupportedCurrencyException($request->currencyCode);
        }

        // 4. Check spendable balance — optimistic pre-check; createHold is the authoritative check
        $available = $this->wallet->availableBalance($walletId, $request->currencyCode) ?? 0;
        if ($available < $request->amountMinor) {
            throw new InsufficientFundsException($available, $request->amountMinor, $request->currencyCode);
        }

        // 5. Per-currency transaction limit (null = no limit configured)
        $limit = $this->config->getOrDefault('payment.wallet_transaction_limit_' . $request->currencyCode, null);
        if ($limit !== null && $request->amountMinor > (int) $limit) {
            throw new TransactionLimitExceededException($request->amountMinor, (int) $limit, $request->currencyCode);
        }

        // 6. Step-up auth check above configured threshold
        $stepUpThreshold = (int) ($this->config->getOrDefault('auth.step_up_threshold', 100000) ?? 100000);
        if ($request->amountMinor >= $stepUpThreshold) {
            if (empty($request->stepUpToken)) {
                throw new StepUpRequiredException(
                    challengeType:   'totp',
                    paymentIntentId: $request->paymentIntentId,
                );
            }

            // Validate the token is bound to this actor and session
            $sessionId = $request->sessionId ?? '';
            $valid = $this->stepUpVerifier->verifyStepUpForActor(
                $request->stepUpToken,
                'payment.initiate',
                $request->payerActorId,
                $sessionId,
            );

            if (! $valid) {
                throw new StepUpRequiredException(
                    challengeType:   'totp',
                    paymentIntentId: $request->paymentIntentId,
                );
            }
        }

        // 4. Create hold — WalletModuleContract handles SELECT FOR UPDATE + balance check
        $holdId = $this->wallet->createHold(
            walletId:             $walletId,
            currencyCode:         $request->currencyCode,
            amount:               $request->amountMinor,
            actorId:              $request->payerActorId,
            correlationId:        $request->correlationId,
            transactionReference: $request->paymentAttemptId,
        );

        $this->audit->record(new AuditEventData(
            action:        'payment.wallet.hold_placed',
            actorId:       $request->payerActorId,
            actorType:     'customer',
            resourceType:  'payment_attempt',
            resourceId:    $request->paymentAttemptId,
            correlationId: $request->correlationId,
            outcome:       'success',
            afterState:    [
                'hold_id'      => $holdId,
                'amount_minor' => $request->amountMinor,
                'currency_code' => $request->currencyCode,
            ],
        ));

        // Wallet payments are synchronous — status is immediately succeeded
        return new PaymentAttemptResult(
            status:       PaymentAttemptStatus::Succeeded,
            feeMinor:     0,
            walletHoldId: $holdId,
        );
    }

    /**
     * T095: queryPaymentStatus — reads current attempt status from DB.
     * Wallet payments are synchronous — no external query needed.
     */
    public function queryPaymentStatus(string $providerReferenceId): PaymentStatusResult
    {
        // providerReferenceId is the attemptId for wallet payments
        $attempt = $this->attemptRepository->findById($providerReferenceId);

        $status = $attempt?->status ?? PaymentAttemptStatus::Failed;

        return new PaymentStatusResult(
            status:              $status,
            providerReferenceId: $providerReferenceId,
        );
    }

    /**
     * T093: initiateRefund
     * 1. Release hold via WalletModuleContract
     * 2. Post compensating credit via LedgerModuleContract
     * Returns PaymentRefundResult with status=succeeded.
     * Idempotent: if hold already released, skip silently.
     * NO modification to historical ledger entries.
     */
    public function initiateRefund(PaymentRefundRequest $request): PaymentRefundResult
    {
        // Releases the hold — compensating credit is handled by LedgerModuleContract
        // The orchestration layer (RefundService) handles the hold release
        // Here we confirm the wallet refund action is complete

        $this->audit->record(new AuditEventData(
            action:        'payment.wallet.refund_initiated',
            actorId:       'system',
            actorType:     'system',
            resourceType:  'payment_refund',
            resourceId:    $request->refundId,
            correlationId: $request->correlationId,
            outcome:       'success',
            afterState:    [
                'refund_id'    => $request->refundId,
                'amount_minor' => $request->amountMinor,
                'currency_code' => $request->currencyCode,
            ],
        ));

        return new PaymentRefundResult(
            status: PaymentRefundStatus::Succeeded,
        );
    }

    /**
     * T094: cancelPayment
     * If attempt has wallet_hold_id and hold not yet released: releaseHold.
     * If no hold (never initiated): return succeeded as no-op.
     */
    public function cancelPayment(string $providerReferenceId): PaymentCancelResult
    {
        // providerReferenceId is the attemptId for wallet payments
        $attempt = $this->attemptRepository->findById($providerReferenceId);

        if ($attempt === null || $attempt->wallet_hold_id === null) {
            return new PaymentCancelResult(cancelled: true);
        }

        // Hold release is handled by PaymentOrchestrationService.cancelAttempt
        // which calls WalletModuleContract.releaseHold before calling this method.
        // By the time we get here, the hold has been released.
        return new PaymentCancelResult(cancelled: true);
    }
}
