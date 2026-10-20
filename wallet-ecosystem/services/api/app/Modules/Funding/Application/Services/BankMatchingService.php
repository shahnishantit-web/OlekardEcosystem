<?php

declare(strict_types=1);

namespace App\Modules\Funding\Application\Services;

use App\Modules\Funding\Domain\Enums\FundingStatus;
use App\Modules\Funding\Domain\Events\BankDepositReconciled;
use App\Modules\Funding\Domain\Events\FundingTransactionAmountMismatch;
use App\Modules\Funding\Domain\Events\UnmatchedDepositReceived;
use App\Modules\Funding\Infrastructure\Models\FundingTransactionModel;
use App\Modules\Funding\Infrastructure\Models\UnmatchedDepositModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Matches an inbound bank notification to an awaiting_match FundingTransaction.
 *
 * Matching logic (FR-E08-055 — FR-E08-062):
 * 1. Find FundingTransaction in `awaiting_match` status with matching bank_reference_code
 *    where bank_reference_expires_at > now().
 * 2. If no match → store as UnmatchedDeposit, emit UnmatchedDepositReceived event.
 * 3. If amount differs by more than 1 minor unit → emit AmountMismatch, store as unmatched.
 * 4. If match + amount correct → call FundingCreditService to post journal and complete tx.
 */
final class BankMatchingService
{
    public function __construct(
        private readonly FundingCreditService      $creditService,
        private readonly AuditServiceInterface     $audit,
        private readonly EventPublisherInterface   $events,
        private readonly MetricsCollectorInterface $metrics,
    ) {}

    /**
     * @param array{
     *   bank_reference_code:  string,
     *   amount:               int,
     *   currency_code:        string,
     *   bank_notification_id: string,
     *   correlation_id:       string,
     * } $notification
     */
    public function match(array $notification): void
    {
        $bankRefCode     = $notification['bank_reference_code'];
        $amount          = $notification['amount'];
        $currencyCode    = $notification['currency_code'];
        $notificationId  = $notification['bank_notification_id'];
        $correlationId   = $notification['correlation_id'];

        $tx = FundingTransactionModel::where('bank_reference_code', $bankRefCode)
            ->where('status', FundingStatus::AwaitingMatch->value)
            ->where('bank_reference_expires_at', '>', Carbon::now())
            ->where('pocket_currency_code', $currencyCode)
            ->lockForUpdate()
            ->first();

        if ($tx === null) {
            // No match — store as unmatched deposit
            $this->storeUnmatched($bankRefCode, $amount, $currencyCode, $notificationId, $correlationId);
            return;
        }

        // Amount mismatch guard
        if (abs($tx->gross_amount - $amount) > 1) {
            $this->events->publish(new FundingTransactionAmountMismatch(
                fundingTransactionId:   $tx->id,
                expectedAmount:         $tx->gross_amount,
                receivedAmount:         $amount,
                bankReferenceCode:      $bankRefCode,
                correlationId:          $correlationId,
            ));

            $this->storeUnmatched($bankRefCode, $amount, $currencyCode, $notificationId, $correlationId, $tx->id);

            $this->metrics->increment('funding.bank_match.amount_mismatch', ['currency' => $currencyCode]);
            return;
        }

        // Successful match — credit wallet
        $this->creditService->credit([
            ...(isset($notification['float_account_reference']) ? ['float_account_reference' => $notification['float_account_reference']] : []),
            'transaction_id'        => $tx->id,
            'provider_reference_id' => 'BANK-' . $notificationId,
            'correlation_id'        => $correlationId,
        ]);

        \App\Modules\Funding\Infrastructure\Models\BankReconciliationModel::create([
            'id' => (string) Uuid::uuid7(), 'funding_transaction_id' => $tx->id,
            'bank_notification_id' => $notificationId, 'bank_reference_code' => $bankRefCode,
            'matched_amount' => $amount, 'currency_code' => $currencyCode, 'reconciled_at' => now(),
        ]);

        $this->events->publish(new BankDepositReconciled(
            fundingTransactionId: $tx->id,
            bankReferenceCode:    $bankRefCode,
            amount:               $amount,
            correlationId:        $correlationId,
        ));

        $this->audit->record(new AuditEventData(
            action:       'funding.bank-deposit.reconciled',
            actorType:    'SYSTEM',
            resourceType: 'funding_transaction',
            correlationId: $correlationId,
            outcome:      'success',
            resourceId:   $tx->id,
        ));

        $this->metrics->increment('funding.bank_match.success', ['currency' => $currencyCode]);
    }

    private function storeUnmatched(
        string $bankRefCode,
        int $amount,
        string $currencyCode,
        string $notificationId,
        string $correlationId,
        ?string $fundingTransactionId = null,
    ): void {
        $unmatched = new UnmatchedDepositModel();
        $unmatched->id                    = (string) Uuid::uuid7();
        $unmatched->bank_reference_code   = $bankRefCode;
        $unmatched->amount                = $amount;
        $unmatched->currency_code         = $currencyCode;
        $unmatched->bank_notification_id  = $notificationId;
        $unmatched->funding_transaction_id = $fundingTransactionId;
        $unmatched->resolution_status     = 'unresolved';
        $unmatched->save();

        $this->events->publish(new UnmatchedDepositReceived(
            unmatchedDepositId:   $unmatched->id,
            bankReferenceCode:    $bankRefCode,
            amount:               $amount,
            currencyCode:         $currencyCode,
            correlationId:        $correlationId,
        ));

        $this->metrics->increment('funding.bank_match.unmatched', ['currency' => $currencyCode]);
    }
}
