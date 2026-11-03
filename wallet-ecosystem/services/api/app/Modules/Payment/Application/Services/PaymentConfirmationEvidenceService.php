<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Identity\Domain\Contracts\ServiceActor;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Payment\Domain\Contracts\PaymentInternalConfirmationContract;
use App\Modules\Payment\Domain\Enums\PaymentAccountingStatus;
use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use App\Modules\Payment\Domain\Enums\PaymentFulfilmentStatus;
use App\Modules\Payment\Domain\Enums\PaymentIntentStatus;
use App\Modules\Payment\Domain\ValueObjects\PaymentConfirmationEvidence;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class PaymentConfirmationEvidenceService implements PaymentInternalConfirmationContract
{
    public function __construct(private readonly LedgerModuleContract $ledger) {}

    public function confirmationEvidence(
        ServiceActor $service,
        string $paymentIntentId,
        string $organisationId,
        string $purposeType,
        string $purposeReference,
    ): ?PaymentConfirmationEvidence {
        $this->assertScope($service, 'payment.confirmation.read', $organisationId, $purposeType);
        if ($purposeType !== 'paid_event_ticket') return null;

        $intent = PaymentIntentModel::query()->whereKey($paymentIntentId)
            ->where('organisation_id', $organisationId)
            ->where('purpose_type', $purposeType)
            ->where('purpose_reference', $purposeReference)->first();
        if ($intent === null || $intent->status !== PaymentIntentStatus::Succeeded
            || $intent->accounting_status !== PaymentAccountingStatus::LedgerPosted) return null;

        $attempt = PaymentAttemptModel::query()->where('payment_intent_id', $intent->id)
            ->where('status', PaymentAttemptStatus::Succeeded->value)->latest('confirmed_at')->first();
        if ($attempt === null || $attempt->captured_amount_minor === null
            || $attempt->captured_currency_code !== $intent->currency_code
            || $attempt->captured_amount_minor !== $intent->amount_minor
            || $attempt->ledger_journal_id === null || $attempt->capture_evidence_reference === null
            || $attempt->ledger_debit_account_reference === null || $attempt->ledger_credit_account_reference === null) return null;

        $journal = $this->ledger->findPostedJournalEvidence('payment:'.$intent->id.':ack');
        if ($journal === null || $journal->journalId !== $attempt->ledger_journal_id
            || $journal->status !== 'posted' || $journal->transactionReference !== $intent->id
            || $journal->currencyCode !== $intent->currency_code
            || $journal->entryType !== 'debit' || count($journal->lines) !== 2
            || count(array_filter($journal->lines, static fn (array $line): bool => $line['direction'] === 'debit'
                && $line['account_reference'] === $attempt->ledger_debit_account_reference
                && $line['amount_minor'] === $attempt->captured_amount_minor
                && $line['currency_code'] === $intent->currency_code)) !== 1
            || count(array_filter($journal->lines, static fn (array $line): bool => $line['direction'] === 'credit'
                && $line['account_reference'] === $attempt->ledger_credit_account_reference
                && $line['amount_minor'] === $attempt->captured_amount_minor
                && $line['currency_code'] === $intent->currency_code)) !== 1
            || count(array_filter($journal->lines, static fn (array $line): bool => $line['direction'] === 'debit'
                && $line['amount_minor'] === $attempt->captured_amount_minor
                && $line['currency_code'] === $intent->currency_code)) !== 1
            || count(array_filter($journal->lines, static fn (array $line): bool => $line['direction'] === 'credit'
                && $line['amount_minor'] === $attempt->captured_amount_minor
                && $line['currency_code'] === $intent->currency_code)) !== 1) return null;

        return new PaymentConfirmationEvidence(
            paymentIntentId: $intent->id,
            paymentAttemptId: $attempt->id,
            organisationId: $intent->organisation_id,
            payerActorId: $intent->payer_actor_id,
            purposeType: $intent->purpose_type,
            purposeReference: $intent->purpose_reference,
            capturedAmountMinor: $attempt->captured_amount_minor,
            capturedCurrencyCode: $attempt->captured_currency_code,
            ledgerJournalId: $attempt->ledger_journal_id,
            accountingStatus: $intent->accounting_status->value,
        );
    }

    public function acknowledgeFulfilment(
        ServiceActor $service,
        string $paymentIntentId,
        string $organisationId,
        string $purposeType,
        string $purposeReference,
        string $fulfilmentReference,
        string $idempotencyKey,
    ): void {
        $this->assertScope($service, 'payment.fulfilment.ack', $organisationId, $purposeType);
        if ($fulfilmentReference === '' || strlen($fulfilmentReference) > 255
            || $idempotencyKey === '' || strlen($idempotencyKey) > 255) {
            throw new \InvalidArgumentException('Durable fulfilment reference and idempotency key are required.');
        }

        DB::connection('payment')->transaction(function () use (
            $service, $paymentIntentId, $organisationId, $purposeType, $purposeReference,
            $fulfilmentReference, $idempotencyKey
        ): void {
            $intent = PaymentIntentModel::query()->whereKey($paymentIntentId)->lockForUpdate()->first();
            if ($intent === null || $intent->organisation_id !== $organisationId
                || $intent->purpose_type !== $purposeType || $intent->purpose_reference !== $purposeReference) {
                throw new \DomainException('Payment purpose scope mismatch.');
            }
            $existing = DB::connection('payment')->table('payment.fulfilment_acknowledgements')
                ->where('payment_intent_id', $paymentIntentId)->first();
            if ($existing !== null) {
                if ($existing->idempotency_key === $idempotencyKey
                    && $existing->fulfilment_reference === $fulfilmentReference
                    && $intent->fulfilment_status === PaymentFulfilmentStatus::Fulfilled) return;
                throw new \DomainException('Conflicting fulfilment acknowledgement already exists.');
            }
            if ($intent->status !== PaymentIntentStatus::Succeeded
                || $intent->accounting_status !== PaymentAccountingStatus::LedgerPosted
                || $intent->fulfilment_status !== PaymentFulfilmentStatus::Pending
                || $this->confirmationEvidence($service, $paymentIntentId, $organisationId, $purposeType, $purposeReference) === null) {
                throw new \DomainException('Payment evidence is not eligible for fulfilment acknowledgement.');
            }
            DB::connection('payment')->table('payment.fulfilment_acknowledgements')->insert([
                'id' => (string) Uuid::uuid7(), 'payment_intent_id' => $paymentIntentId,
                'organisation_id' => $organisationId, 'purpose_type' => $purposeType,
                'purpose_reference' => $purposeReference, 'consumer_service_id' => $service->serviceId,
                'fulfilment_reference' => $fulfilmentReference, 'idempotency_key' => $idempotencyKey,
                'acknowledged_at' => now(), 'created_at' => now(),
            ]);
            $intent->fulfilment_status = PaymentFulfilmentStatus::Fulfilled;
            $intent->save();
        });
    }

    private function assertScope(ServiceActor $service, string $action, string $organisationId, string $purposeType): void
    {
        $required = $action.':'.$organisationId.':'.$purposeType;
        if ($service->actorType !== 'SERVICE' || $service->serviceId !== 'events'
            || ! in_array($required, $service->scopes, true)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Service identity lacks the required payment scope.');
        }
    }
}
