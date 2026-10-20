<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Application\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Transfer\Application\Contracts\TransferRiskService;
use App\Modules\Transfer\Domain\Enums\TransferFailureReason;
use App\Modules\Transfer\Domain\Enums\TransferStatus;
use App\Modules\Transfer\Domain\Events\TransferInitiated;
use App\Modules\Transfer\Domain\Events\TransferRiskRejected;
use App\Modules\Transfer\Domain\Exceptions\InvalidLookupTokenException;
use App\Modules\Transfer\Infrastructure\Models\TransferTransactionModel;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class TransferInitiationService
{
    public function __construct(
        private readonly WalletModuleContract       $wallet,
        private readonly LookupTokenService         $lookupTokens,
        private readonly TransferReferenceGenerator $referenceGenerator,
        private readonly TransferFeeService         $feeService,
        private readonly TransferLimitService       $limitService,
        private readonly TransferRiskService        $risk,
        private readonly AuditServiceInterface      $audit,
        private readonly EventPublisherInterface    $events,
        private readonly TransferMetricsService     $metrics,
    ) {}

    /**
     * @param array{
     *   sender_customer_id: string,
     *   sender_wallet_id:   string,
     *   lookup_token:       string,
     *   gross_amount:       int,
     *   currency_code:      string,
     *   idempotency_key:    string,
     *   correlation_id:     string,
     * } $request
     *
     * @throws InvalidLookupTokenException
     * @throws \App\Modules\Transfer\Domain\Exceptions\InsufficientBalanceException
     * @throws \App\Modules\Transfer\Domain\Exceptions\DailyLimitExceededException
     * @throws \InvalidArgumentException
     */
    public function initiate(array $request): TransferTransactionModel
    {
        return DB::connection('platform')->transaction(fn () => $this->initiateWithinTransaction($request));
    }

    private function initiateWithinTransaction(array $request): TransferTransactionModel
    {
        $senderCustomerId = $request['sender_customer_id'];
        $senderWalletId   = $request['sender_wallet_id'];
        $lookupToken      = $request['lookup_token'];
        $grossAmount      = $request['gross_amount'];
        $currencyCode     = $request['currency_code'];
        $idempotencyKey   = $request['idempotency_key'];
        $correlationId    = $request['correlation_id'];

        // Idempotency check: return existing pending record if same key
        $existing = TransferTransactionModel::where('idempotency_key', $idempotencyKey)
            ->where('sender_customer_id', $senderCustomerId)->first();
        if ($existing !== null) {
            return $existing;
        }

        // Ownership validation
        if ($this->wallet->ownerActorId($senderWalletId) !== $senderCustomerId) {
            throw new \DomainException("Wallet [{$senderWalletId}] does not belong to customer [{$senderCustomerId}].");
        }

        // Consume lookup token (single-use)
        $tokenPayload = $this->lookupTokens->consume($lookupToken);
        if ($tokenPayload === null) {
            throw new InvalidLookupTokenException();
        }

        $recipientCustomerId      = $tokenPayload['recipient_customer_id'];
        $recipientWalletId        = $tokenPayload['recipient_wallet_id'];
        $recipientPocketLedgerRef = $tokenPayload['recipient_pocket_ledger_ref'];
        $tokenCurrency            = $tokenPayload['currency_code'];

        // Currency match check
        if ($tokenCurrency !== $currencyCode) {
            throw new \InvalidArgumentException(
                "Currency mismatch: token currency [{$tokenCurrency}] does not match requested [{$currencyCode}]."
            );
        }

        // Resolve sender pocket
        $senderPocketRef = $this->wallet->getPocketLedgerAccountRef($senderWalletId, $currencyCode);
        if ($senderPocketRef === null) {
            throw new \DomainException("No [{$currencyCode}] pocket found for sender wallet [{$senderWalletId}].");
        }

        // Amount limits
        $this->limitService->validateMin($grossAmount, $currencyCode);
        $this->limitService->validateMax($grossAmount, $currencyCode);

        // Pre-check balance
        $available = $this->wallet->availableBalance($senderWalletId, $currencyCode);
        if ($available === null || $available < $grossAmount) {
            throw \App\Modules\Transfer\Domain\Exceptions\InsufficientBalanceException::forAmount($grossAmount, $available ?? 0);
        }

        // Daily limit
        $this->limitService->validateDailyLimit($senderCustomerId, $grossAmount, $currencyCode);

        // Fee calculation
        $rateBps = $this->feeService->getCurrentRateBps($currencyCode);
        $fees    = $this->feeService->calculate($grossAmount, $rateBps);

        // Risk evaluation
        $riskResult = $this->risk->evaluate([
            'sender_customer_id'    => $senderCustomerId,
            'sender_wallet_id'      => $senderWalletId,
            'recipient_customer_id' => $recipientCustomerId,
            'recipient_wallet_id'   => $recipientWalletId,
            'currency_code'         => $currencyCode,
            'gross_amount'          => $grossAmount,
            'correlation_id'        => $correlationId,
        ]);

        if (! $riskResult->approved) {
            $this->events->publish(new TransferRiskRejected(
                transferId:     '',
                senderWalletId: $senderWalletId,
                currencyCode:   $currencyCode,
                grossAmount:    $grossAmount,
                riskReason:     $riskResult->reason,
                occurredAt:     now()->toIso8601String(),
                correlationId:  $correlationId,
            ));

            throw new \DomainException("Transfer rejected by risk engine: {$riskResult->reason}");
        }

        // Create transfer record
        $tx = new TransferTransactionModel();
        $tx->id                         = (string) Uuid::uuid7();
        $tx->transfer_reference         = $this->referenceGenerator->generate();
        $tx->sender_customer_id         = $senderCustomerId;
        $tx->sender_wallet_id           = $senderWalletId;
        $tx->sender_pocket_ledger_ref   = $senderPocketRef;
        $tx->recipient_customer_id      = $recipientCustomerId;
        $tx->recipient_wallet_id        = $recipientWalletId;
        $tx->recipient_pocket_ledger_ref = $recipientPocketLedgerRef;
        $tx->currency_code              = $currencyCode;
        $tx->gross_amount               = $grossAmount;
        $tx->fee_amount                 = $fees['fee_amount'];
        $tx->net_amount                 = $fees['net_amount'];
        $tx->fee_rate_bps               = $fees['fee_rate_bps'];
        $tx->status                     = TransferStatus::Pending;
        $tx->failure_reason             = null;
        $tx->idempotency_key            = $idempotencyKey;
        $tx->correlation_id             = $correlationId;
        $tx->save();

        $this->events->publish(new TransferInitiated(
            transferId:         $tx->id,
            senderWalletId:     $senderWalletId,
            recipientWalletId:  $recipientWalletId,
            currencyCode:       $currencyCode,
            grossAmount:        $grossAmount,
            feeAmount:          $fees['fee_amount'],
            netAmount:          $fees['net_amount'],
            occurredAt:         now()->toIso8601String(),
            correlationId:      $correlationId,
        ));

        $this->audit->record(new AuditEventData(
            action:        'transfer.initiated',
            actorId:       $senderCustomerId,
            actorType:     'CUSTOMER',
            resourceType:  'transfer_transaction',
            resourceId:    $tx->id,
            correlationId: $correlationId,
            outcome:       'success',
            afterState:    [
                'transfer_reference' => $tx->transfer_reference,
                'gross_amount'       => $grossAmount,
                'fee_amount'         => $fees['fee_amount'],
                'net_amount'         => $fees['net_amount'],
                'currency_code'      => $currencyCode,
                'status'             => TransferStatus::Pending->value,
            ],
        ));

        $this->metrics->transferInitiated($currencyCode);

        return $tx;
    }
}
