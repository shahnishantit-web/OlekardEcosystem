<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Application\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use App\Modules\Withdrawal\Domain\Enums\WithdrawalChannel;
use App\Modules\Withdrawal\Domain\Enums\WithdrawalStatus;
use App\Modules\Withdrawal\Domain\Events\WithdrawalInitiated;
use App\Modules\Withdrawal\Domain\Exceptions\InsufficientBalanceException;
use App\Modules\Withdrawal\Infrastructure\Models\WithdrawalTransactionModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class WithdrawalInitiationService
{
    public function __construct(
        private readonly WalletModuleContract        $wallet,
        private readonly WithdrawalFeeService        $feeService,
        private readonly WithdrawalLimitService      $limitService,
        private readonly WithdrawalMetricsService    $metrics,
        private readonly AuditServiceInterface       $audit,
        private readonly EventPublisherInterface     $events,
        private readonly ConfigurationServiceInterface $config,
    ) {}

    /**
     * @param array{
     *   customer_id:         string,
     *   wallet_id:           string,
     *   currency_code:       string,
     *   channel:             WithdrawalChannel,
     *   gross_amount:        int,
     *   payout_destination:  array,
     *   idempotency_key:     string,
     *   correlation_id:      string,
     * } $request
     */
    public function initiate(array $request): WithdrawalTransactionModel
    {
        return DB::connection('platform')->transaction(fn () => $this->initiateWithinTransaction($request));
    }

    private function initiateWithinTransaction(array $request): WithdrawalTransactionModel
    {
        $customerId        = $request['customer_id'];
        $walletId          = $request['wallet_id'];
        $currencyCode      = $request['currency_code'];
        $channel           = $request['channel'];
        $grossAmount       = $request['gross_amount'];
        $payoutDestination = $request['payout_destination'];
        $idempotencyKey    = $request['idempotency_key'];
        $correlationId     = $request['correlation_id'];

        // Idempotency check — return existing if same key + customer
        $existing = WithdrawalTransactionModel::where('idempotency_key', $idempotencyKey)
            ->where('customer_id', $customerId)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        // Wallet ownership
        if ($this->wallet->ownerActorId($walletId) !== $customerId) {
            throw new \DomainException("Wallet [{$walletId}] does not belong to customer [{$customerId}].");
        }

        // Pocket existence
        if (! $this->wallet->pocketExists($walletId, $currencyCode)) {
            throw new \DomainException("No [{$currencyCode}] pocket on wallet [{$walletId}].");
        }

        // Channel availability for currency
        $availableCurrenciesKey = "withdrawal.channel.{$channel->value}.available_currencies";
        $availableCurrencies    = (string) ($this->config->get($availableCurrenciesKey) ?? '');
        $currencyList           = array_filter(array_map('trim', explode(',', $availableCurrencies)));
        if (! in_array($currencyCode, $currencyList, true)) {
            throw new \DomainException("Channel [{$channel->value}] is not available for currency [{$currencyCode}].");
        }

        // Payout destination validation
        $this->validatePayoutDestination($channel, $payoutDestination);

        // Available balance check at initiation (re-checked at confirmation)
        $available = $this->wallet->availableBalance($walletId, $currencyCode);
        if ($available === null || $available < $grossAmount) {
            throw new InsufficientBalanceException($grossAmount, $available ?? 0);
        }

        // Fee calculation
        $fees = $this->feeService->calculate($grossAmount, $channel, $currencyCode);

        // [CC-E10-002] Limit check + INSERT inside same DB transaction (atomic)
        $tx = DB::connection('platform')->transaction(function () use (
            $customerId, $walletId, $currencyCode, $channel, $grossAmount,
            $payoutDestination, $idempotencyKey, $correlationId, $fees,
        ): WithdrawalTransactionModel {
            $this->limitService->validate($grossAmount, $channel, $currencyCode, $customerId);

            $id  = (string) Uuid::uuid7();
            $ref = (string) Uuid::uuid7();

            $model                       = new WithdrawalTransactionModel();
            $model->id                   = $id;
            $model->wallet_id            = $walletId;
            $model->pocket_currency_code = $currencyCode;
            $model->customer_id          = $customerId;
            $model->channel              = $channel;
            $model->status               = WithdrawalStatus::Pending;
            $model->gross_amount         = $grossAmount;
            $model->fee_amount           = $fees['fee_amount'];
            $model->net_payout_amount    = $fees['net_payout_amount'];
            $model->fee_rate_bps         = $fees['fee_rate_bps'];
            $model->idempotency_key      = $idempotencyKey;
            $model->payout_destination   = $payoutDestination;
            $model->risk_check_status    = 'pending';
            $model->transaction_reference = $ref;
            $model->initiated_by         = $customerId;
            $model->correlation_id       = $correlationId;
            $model->save();

            return $model;
        });

        $this->events->publish(new WithdrawalInitiated(
            withdrawalTransactionId: $tx->id,
            walletId:                $walletId,
            currencyCode:            $currencyCode,
            grossAmount:             $grossAmount,
            channel:                 $channel->value,
            transactionReference:    $tx->transaction_reference,
            occurredAt:              now()->toIso8601String(),
            correlationId:           $correlationId,
        ));

        $this->audit->record(new AuditEventData(
            action:        'withdrawal.transaction.initiated',
            actorId:       $customerId,
            actorType:     'CUSTOMER',
            resourceType:  'withdrawal_transaction',
            resourceId:    $tx->id,
            correlationId: $correlationId,
            outcome:       'success',
            afterState:    [
                'transaction_reference' => $tx->transaction_reference,
                'gross_amount'          => $grossAmount,
                'fee_amount'            => $fees['fee_amount'],
                'net_payout_amount'     => $fees['net_payout_amount'],
                'channel'               => $channel->value,
                'currency_code'         => $currencyCode,
                'status'                => WithdrawalStatus::Pending->value,
            ],
        ));

        $this->metrics->recordInitiated($channel->value);

        return $tx;
    }

    private function validatePayoutDestination(WithdrawalChannel $channel, array $destination): void
    {
        if ($channel === WithdrawalChannel::MobileMoney) {
            if (empty($destination['phone_number'])) {
                throw new \DomainException('payout_destination.phone_number is required for mobile_money.');
            }
        }

        if ($channel === WithdrawalChannel::BankTransfer) {
            if (empty($destination['account_number'])) {
                throw new \DomainException('payout_destination.account_number is required for bank_transfer.');
            }
            if (empty($destination['bank_code'])) {
                throw new \DomainException('payout_destination.bank_code is required for bank_transfer.');
            }
        }
    }
}
