<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application\Services;

use App\Modules\Ledger\Domain\Enums\JournalEntryType;
use App\Modules\Ledger\Domain\Enums\JournalLineDirection;
use App\Modules\Ledger\Domain\Enums\JournalStatus;
use App\Modules\Ledger\Domain\Enums\LedgerAccountStatus;
use App\Modules\Ledger\Domain\Events\LedgerEntryPostedEvent;
use App\Modules\Ledger\Domain\Exceptions\AccountClosedException;
use App\Modules\Ledger\Domain\Exceptions\CurrencyMismatchException;
use App\Modules\Ledger\Domain\Exceptions\InvalidAmountException;
use App\Modules\Ledger\Domain\Exceptions\UnbalancedJournalException;
use App\Modules\Ledger\Infrastructure\Models\JournalLineModel;
use App\Modules\Ledger\Infrastructure\Repositories\EloquentJournalRepository;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\IdempotencyLock;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

class LedgerPostingService
{
    public function __construct(
        private readonly LedgerAccountService $accountService,
        private readonly EloquentJournalRepository $journalRepo,
        private readonly IdempotencyServiceInterface $idempotencyService,
        private readonly EventPublisherInterface $outboxPublisher,
        private readonly AuditServiceInterface $auditService,
        private readonly MetricsCollectorInterface $metrics,
    ) {}

    /**
     * Post a balanced double-entry journal.
     *
     * Returns the journal ID (UUID v7). Returns idempotent replay journal ID on duplicate key.
     */
    public function post(array $request, string $actorId, string $entryType): string
    {
        if ($entryType === JournalEntryType::Reversal->value) {
            throw new \InvalidArgumentException('entry_type "reversal" is internal only; use the reverse endpoint.');
        }

        $idempotencyKey = $request['idempotency_key'];
        $correlationId  = $request['correlation_id'];
        $currencyCode   = $request['currency_code'];
        $lines          = $request['lines'];

        // Step 1: Idempotency fast path (before any DB write)
        $cached = $this->idempotencyService->findCompletedResponse($idempotencyKey, $actorId);
        if ($cached !== null) {
            return $cached['body']['journal_id'];
        }

        // Step 2: Validate lines (pure computation — no DB)
        $this->validateLines($lines, $currencyCode);

        $journalId = (string) Uuid::uuid7();
        $lock      = null;

        try {
            DB::connection('platform')->transaction(function () use (
                &$lock,
                $journalId,
                $idempotencyKey,
                $entryType,
                $currencyCode,
                $request,
                $lines,
                $actorId,
                $correlationId,
            ): void {
                // Step 3: Find or auto-register accounts; validate they are active
                $accounts = [];
                foreach ($lines as $line) {
                    $ref     = $line['ledger_account_reference'];
                    $account = $this->accountService->findOrAutoRegister($ref, $currencyCode, $actorId);
                    $status  = $account->status instanceof \BackedEnum
                        ? $account->status->value : $account->status;
                    if ($status === LedgerAccountStatus::Closed->value) {
                        throw AccountClosedException::forReference($ref);
                    }
                    $accounts[$ref] = $account;
                }

                // Step 4a: Acquire idempotency lock (INSERT ON CONFLICT DO NOTHING race guard)
                $lock = $this->idempotencyService->acquireLock(
                    idempotencyKey: $idempotencyKey,
                    actorId:        $actorId,
                    requestHash:    md5(json_encode($lines) . $currencyCode . $entryType),
                    httpMethod:     'POST',
                    httpPath:       '/v1/ledger/entries/' . strtolower($entryType),
                    correlationId:  $correlationId,
                );

                // Step 4b: Insert journal
                $journal = $this->journalRepo->create([
                    'id'                    => $journalId,
                    'idempotency_key'       => $idempotencyKey,
                    'entry_type'            => JournalEntryType::from($entryType)->value,
                    'currency_code'         => $currencyCode,
                    'status'                => JournalStatus::Posted->value,
                    'transaction_reference' => $request['transaction_reference'] ?? null,
                    'reversed_journal_id'   => null,
                    'correlation_id'        => $correlationId,
                    'description'           => $request['description'] ?? null,
                    'posted_at'             => now(),
                    'created_by'            => $actorId,
                ]);

                // Step 4c: Insert journal lines
                foreach ($lines as $line) {
                    $lineModel = new JournalLineModel([
                        'id'                => (string) Uuid::uuid7(),
                        'journal_id'        => $journal->id,
                        'ledger_account_id' => $accounts[$line['ledger_account_reference']]->id,
                        'direction'         => JournalLineDirection::from($line['direction'])->value,
                        'amount'            => $line['amount'],
                        'currency_code'     => $currencyCode,
                    ]);
                    $lineModel->save();
                }

                // Step 4d: Outbox event
                $this->outboxPublisher->publish(new LedgerEntryPostedEvent(
                    journalId:             $journal->id,
                    entryType:             $entryType,
                    currencyCode:          $currencyCode,
                    transactionReference:  $request['transaction_reference'] ?? null,
                    actorId:               $actorId,
                    correlationId:         $correlationId,
                ));

                // Step 4e: Audit
                $this->auditService->record(new AuditEventData(
                    action:        'ledger.entry.posted',
                    actorType:     'ADMIN',
                    resourceType:  'journal',
                    correlationId: $correlationId,
                    outcome:       'success',
                    actorId:       $actorId,
                    resourceId:    $journal->id,
                    afterState:    [
                        'entry_type'            => $entryType,
                        'currency_code'         => $currencyCode,
                        'transaction_reference' => $request['transaction_reference'] ?? null,
                    ],
                ));

                Log::info('ledger.entries.posted', [
                    'journal_id'   => $journal->id,
                    'entry_type'   => $entryType,
                    'currency_code' => $currencyCode,
                    'actor_id'     => $actorId,
                    'correlation_id' => $correlationId,
                ]);

                $this->metrics->increment('ledger.entries.posted', ['entry_type' => $entryType]);
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Race condition: idempotency_key unique constraint fired — return existing journal
            $existing = $this->journalRepo->findByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                return $existing->id;
            }
            throw $e;
        }

        // Step 5: Record idempotency response outside the transaction
        if ($lock !== null) {
            $this->idempotencyService->recordResponse(
                $lock,
                201,
                ['journal_id' => $journalId],
            );
        }

        return $journalId;
    }

    private function validateLines(array $lines, string $currencyCode): void
    {
        if (count($lines) < 2) {
            throw new \InvalidArgumentException('A journal entry requires at least 2 lines.');
        }

        $debitTotal  = 0;
        $creditTotal = 0;

        foreach ($lines as $line) {
            $amount = $line['amount'] ?? 0;

            if (!is_int($amount) || $amount <= 0) {
                throw InvalidAmountException::nonPositive($amount);
            }

            if (($line['currency_code'] ?? $currencyCode) !== $currencyCode) {
                throw CurrencyMismatchException::lineMismatch($line['currency_code'], $currencyCode);
            }

            if ($line['direction'] === JournalLineDirection::Debit->value) {
                $debitTotal += $amount;
            } else {
                $creditTotal += $amount;
            }
        }

        if ($debitTotal === 0 || $creditTotal === 0) {
            throw UnbalancedJournalException::withTotals($debitTotal, $creditTotal);
        }

        if ($debitTotal !== $creditTotal) {
            throw UnbalancedJournalException::withTotals($debitTotal, $creditTotal);
        }
    }
}
