<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Wallet\Domain\Contracts\WalletCaptureContract;
use App\Modules\Wallet\Domain\Enums\WalletHoldStatus;
use App\Modules\Wallet\Domain\ValueObjects\WalletCaptureEvidence;
use App\Modules\Wallet\Domain\ValueObjects\WalletCaptureRequest;
use App\Modules\Wallet\Infrastructure\Models\CurrencyPocketModel;
use App\Modules\Wallet\Infrastructure\Models\WalletCaptureRecordModel;
use App\Modules\Wallet\Infrastructure\Models\WalletHoldModel;
use App\Modules\Wallet\Infrastructure\Models\WalletModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Wallet-owned capture state machine. Ledger commits on the Platform connection,
 * so Wallet records the same idempotent journal after that commit and recovers it
 * on retry. Capture is available only to isolated automated tests until AD-08 is approved.
 */
final class WalletCaptureService implements WalletCaptureContract
{
    public function __construct(
        private readonly LedgerModuleContract $ledger,
        private readonly AuditServiceInterface $audit,
    ) {}

    public function captureForPayment(WalletCaptureRequest $request): WalletCaptureEvidence
    {
        $existing = WalletCaptureRecordModel::on('wallet')->where('hold_id', $request->holdId)->first();
        if ($existing !== null) {
            return $this->existingForRequest($existing, $request);
        }
        $this->assertTestCaptureEnabled();

        /** @var array{evidence: WalletCaptureEvidence, created: bool} $result */
        $result = DB::connection('wallet')->transaction(function () use ($request): array {
            // Read only to discover the pocket; all state decisions happen after the shared lock.
            $initial = WalletHoldModel::on('wallet')->find($request->holdId);
            if ($initial === null) {
                throw new \DomainException('Wallet hold does not exist.');
            }

            // Lock order for capture/release/expiry is pocket then hold.
            $pocket = CurrencyPocketModel::on('wallet')->lockForUpdate()->find($initial->pocket_id);
            if ($pocket === null) {
                throw new \DomainException('Wallet hold pocket does not exist.');
            }
            $hold = WalletHoldModel::on('wallet')->lockForUpdate()->find($request->holdId);
            if ($hold === null || $hold->pocket_id !== $pocket->id) {
                throw new \DomainException('Wallet hold changed while acquiring its pocket lock.');
            }

            $wallet = WalletModel::on('wallet')->find($pocket->wallet_id);
            if ($wallet === null || $wallet->owner_id !== $request->ownerActorId
                || $hold->created_by !== $request->ownerActorId
                || $hold->organisation_id === null
                || $hold->organisation_id !== $request->organisationId) {
                throw new \DomainException('Wallet hold owner does not match the authenticated payer.');
            }
            $amount = (int) $hold->amount;
            $currency = strtoupper((string) $hold->currency_code);
            if ($hold->transaction_reference !== $request->paymentAttemptId
                || $amount !== $request->expectedAmountMinor
                || $currency !== strtoupper($request->expectedCurrencyCode)
                || strtoupper((string) $pocket->currency_code) !== $currency) {
                throw new \DomainException('Wallet hold does not match the payment attempt amount, currency or reference.');
            }

            $existing = WalletCaptureRecordModel::on('wallet')->where('hold_id', $hold->id)->first();
            if ($existing !== null) {
                return ['evidence' => $this->existingForRequest($existing, $request), 'created' => false];
            }
            if ($hold->status !== WalletHoldStatus::Active
                || ($hold->expires_at !== null && $hold->expires_at->isPast())) {
                throw new \DomainException('Only an unexpired active Wallet hold can be captured.');
            }

            $destination = config('wallet.capture.test_destination_accounts.'.$currency);
            if (! is_string($destination) || trim($destination) === '') {
                throw new \RuntimeException('No test-only capture destination is configured for this currency.');
            }

            $idempotencyKey = 'payment:'.$request->paymentIntentId.':ack';
            $journalId = $this->ledger->postEntry([
                'idempotency_key' => $idempotencyKey,
                'entry_type' => 'debit',
                'currency_code' => $currency,
                'transaction_reference' => $request->paymentIntentId,
                'correlation_id' => $request->correlationId,
                'actor_id' => $request->ownerActorId,
                'description' => 'Wallet committed capture for payment '.$request->paymentIntentId,
                'lines' => [
                    ['ledger_account_reference' => $pocket->ledger_account_reference, 'direction' => 'debit', 'amount' => $amount],
                    ['ledger_account_reference' => $destination, 'direction' => 'credit', 'amount' => $amount],
                ],
            ]);
            $posted = $this->ledger->findPostedJournalEvidence($idempotencyKey);
            if ($posted === null || $posted->journalId !== $journalId || $posted->status !== 'posted'
                || $posted->entryType !== 'debit' || $posted->transactionReference !== $request->paymentIntentId
                || $posted->currencyCode !== $currency || count($posted->lines) !== 2
                || count(array_filter($posted->lines, static fn (array $line): bool => $line['account_reference'] === $pocket->ledger_account_reference
                    && $line['direction'] === 'debit' && $line['amount_minor'] === $amount
                    && $line['currency_code'] === $currency)) !== 1
                || count(array_filter($posted->lines, static fn (array $line): bool => $line['account_reference'] === $destination
                    && $line['direction'] === 'credit' && $line['amount_minor'] === $amount
                    && $line['currency_code'] === $currency)) !== 1) {
                throw new \RuntimeException('Ledger did not return matching posted Wallet capture evidence.');
            }

            $captureId = (string) Uuid::uuid7();
            $capturedAt = now();
            $record = WalletCaptureRecordModel::on('wallet')->create([
                'id' => $captureId,
                'hold_id' => $hold->id,
                'owner_actor_id' => $request->ownerActorId,
                'payment_attempt_id' => $request->paymentAttemptId,
                'payment_intent_id' => $request->paymentIntentId,
                'organisation_id' => $request->organisationId,
                'currency_code' => $currency,
                'amount_minor' => $amount,
                'ledger_journal_id' => $journalId,
                'ledger_idempotency_key' => $idempotencyKey,
                'debit_account_reference' => $pocket->ledger_account_reference,
                'credit_account_reference' => $destination,
                'correlation_id' => $request->correlationId,
                'captured_at' => $capturedAt,
            ]);
            $hold->status = WalletHoldStatus::Captured;
            $hold->captured_at = $capturedAt;
            $hold->save();

            return ['evidence' => $this->toEvidence($record), 'created' => true];
        }, 3);

        if ($result['created']) {
            $this->audit->record(new AuditEventData(
                action: 'wallet.capture.committed',
                actorId: $request->ownerActorId,
                actorType: 'customer',
                resourceType: 'wallet_capture',
                resourceId: $result['evidence']->captureId,
                correlationId: $request->correlationId,
                outcome: 'success',
                afterState: [
                    'hold_id' => $request->holdId,
                    'payment_attempt_id' => $request->paymentAttemptId,
                    'payment_intent_id' => $request->paymentIntentId,
                    'amount_minor' => $result['evidence']->capturedAmountMinor,
                    'currency_code' => $result['evidence']->currencyCode,
                    'ledger_journal_id' => $result['evidence']->ledgerJournalId,
                ],
            ));
        }

        return $result['evidence'];
    }

    public function committedCaptureForHold(string $holdId): ?WalletCaptureEvidence
    {
        $record = WalletCaptureRecordModel::on('wallet')->where('hold_id', $holdId)->first();
        if ($record === null) {
            return null;
        }
        $hold = WalletHoldModel::on('wallet')->find($holdId);
        if ($hold === null || $hold->status !== WalletHoldStatus::Captured
            || $hold->organisation_id === null || $hold->organisation_id !== $record->organisation_id) {
            return null;
        }
        $evidence = $this->toEvidence($record);
        $journal = $this->ledger->findPostedJournalEvidence($evidence->ledgerIdempotencyKey);
        if ($journal === null || $journal->journalId !== $evidence->ledgerJournalId || $journal->status !== 'posted'
            || $journal->transactionReference !== $evidence->paymentIntentId
            || $journal->currencyCode !== $evidence->currencyCode || $journal->entryType !== 'debit'
            || count($journal->lines) !== 2
            || count(array_filter($journal->lines, static fn (array $line): bool => $line['account_reference'] === $evidence->debitAccountReference && $line['direction'] === 'debit'
                && $line['amount_minor'] === $evidence->capturedAmountMinor && $line['currency_code'] === $evidence->currencyCode)) !== 1
            || count(array_filter($journal->lines, static fn (array $line): bool => $line['account_reference'] === $evidence->creditAccountReference && $line['direction'] === 'credit'
                && $line['amount_minor'] === $evidence->capturedAmountMinor && $line['currency_code'] === $evidence->currencyCode)) !== 1) {
            return null;
        }

        return $evidence;
    }

    private function existingForRequest(WalletCaptureRecordModel $record, WalletCaptureRequest $request): WalletCaptureEvidence
    {
        $evidence = $this->toEvidence($record);
        if ($evidence->paymentAttemptId !== $request->paymentAttemptId
            || $evidence->paymentIntentId !== $request->paymentIntentId
            || $evidence->organisationId !== $request->organisationId
            || $evidence->ownerActorId !== $request->ownerActorId
            || $evidence->capturedAmountMinor !== $request->expectedAmountMinor
            || $evidence->currencyCode !== strtoupper($request->expectedCurrencyCode)) {
            throw new \DomainException('Wallet capture idempotency key conflicts with the original request.');
        }

        return $this->committedCaptureForHold($record->hold_id)
            ?? throw new \RuntimeException('Stored Wallet capture has no matching posted Ledger journal.');
    }

    private function toEvidence(WalletCaptureRecordModel $record): WalletCaptureEvidence
    {
        return new WalletCaptureEvidence(
            captureId: $record->id,
            holdId: $record->hold_id,
            ownerActorId: $record->owner_actor_id,
            paymentAttemptId: $record->payment_attempt_id,
            paymentIntentId: $record->payment_intent_id,
            organisationId: $record->organisation_id,
            capturedAmountMinor: (int) $record->amount_minor,
            currencyCode: $record->currency_code,
            ledgerJournalId: $record->ledger_journal_id,
            ledgerIdempotencyKey: $record->ledger_idempotency_key,
            debitAccountReference: $record->debit_account_reference,
            creditAccountReference: $record->credit_account_reference,
            capturedAt: $record->captured_at->toIso8601String(),
        );
    }

    private function assertTestCaptureEnabled(): void
    {
        if (app()->environment('production') || ! app()->environment('testing', 'local', 'development')
            || config('wallet.capture.test_only_enabled') !== true) {
            throw new \RuntimeException('Wallet capture is disabled pending approved AD-08 accounting configuration.');
        }
    }
}
