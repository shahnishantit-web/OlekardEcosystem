<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application\Services;

use App\Modules\Ledger\Domain\Enums\JournalEntryType;
use App\Modules\Ledger\Domain\Enums\JournalLineDirection;
use App\Modules\Ledger\Domain\Enums\JournalStatus;
use App\Modules\Ledger\Domain\Events\LedgerEntryReversedEvent;
use App\Modules\Ledger\Domain\Exceptions\JournalAlreadyReversedException;
use App\Modules\Ledger\Domain\Exceptions\JournalNotFoundException;
use App\Modules\Ledger\Infrastructure\Models\JournalLineModel;
use App\Modules\Ledger\Infrastructure\Repositories\EloquentJournalRepository;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

class LedgerReverseService
{
    public function __construct(
        private readonly EloquentJournalRepository $journalRepo,
        private readonly IdempotencyServiceInterface $idempotencyService,
        private readonly EventPublisherInterface $outboxPublisher,
        private readonly AuditServiceInterface $auditService,
        private readonly MetricsCollectorInterface $metrics,
    ) {}

    /**
     * Create a compensating reversal journal for the given journal ID.
     * Returns the reversal journal ID.
     */
    public function reverse(
        string $journalId,
        string $actorId,
        string $idempotencyKey,
        string $correlationId,
        ?string $description,
    ): string {
        // Step 1: Idempotency fast path
        $cached = $this->idempotencyService->findCompletedResponse($idempotencyKey, $actorId);
        if ($cached !== null) {
            return $cached['body']['reversal_journal_id'];
        }

        // Step 2: Load and validate original journal
        $original = $this->journalRepo->findById($journalId);
        if ($original === null) {
            throw JournalNotFoundException::forId($journalId);
        }

        // Step 3: Ensure this journal is not itself a reversal and has not already been reversed
        $entryTypeValue = $original->entry_type instanceof \BackedEnum
            ? $original->entry_type->value : (string) $original->entry_type;

        if ($entryTypeValue === JournalEntryType::Reversal->value) {
            throw JournalAlreadyReversedException::forId($journalId);
        }

        $alreadyReversed = DB::connection('platform')
            ->table('ledger.journals')
            ->where('reversed_journal_id', $journalId)
            ->where('entry_type', JournalEntryType::Reversal->value)
            ->exists();

        if ($alreadyReversed) {
            throw JournalAlreadyReversedException::forId($journalId);
        }

        // Step 4: Build mirror lines (swap direction)
        $originalLines = $original->lines()->get();
        $reversalId    = (string) Uuid::uuid7();
        $lock          = null;

        DB::connection('platform')->transaction(function () use (
            &$lock,
            $reversalId,
            $journalId,
            $original,
            $originalLines,
            $idempotencyKey,
            $actorId,
            $correlationId,
            $description,
        ): void {
            $lock = $this->idempotencyService->acquireLock(
                idempotencyKey: $idempotencyKey,
                actorId:        $actorId,
                requestHash:    md5($journalId . $actorId),
                httpMethod:     'POST',
                httpPath:       '/v1/ledger/entries/' . $journalId . '/reverse',
                correlationId:  $correlationId,
            );

            // Insert reversal journal header
            $reversalJournal = $this->journalRepo->create([
                'id'                    => $reversalId,
                'idempotency_key'       => $idempotencyKey,
                'entry_type'            => JournalEntryType::Reversal->value,
                'currency_code'         => $original->currency_code,
                'status'                => JournalStatus::Posted->value,
                'transaction_reference' => $original->transaction_reference,
                'reversed_journal_id'   => $journalId,
                'correlation_id'        => $correlationId,
                'description'           => $description ?? 'Reversal of journal ' . $journalId,
                'posted_at'             => now(),
                'created_by'            => $actorId,
            ]);

            // Insert mirrored lines (direction swapped)
            foreach ($originalLines as $line) {
                $originalDirection = $line->direction instanceof \BackedEnum
                    ? $line->direction->value : $line->direction;

                $mirrorDirection = $originalDirection === JournalLineDirection::Debit->value
                    ? JournalLineDirection::Credit->value
                    : JournalLineDirection::Debit->value;

                $mirrorLine = new JournalLineModel([
                    'id'                => (string) Uuid::uuid7(),
                    'journal_id'        => $reversalJournal->id,
                    'ledger_account_id' => $line->ledger_account_id,
                    'direction'         => $mirrorDirection,
                    'amount'            => $line->amount,
                    'currency_code'     => $line->currency_code,
                ]);
                $mirrorLine->save();
            }

            $this->outboxPublisher->publish(new LedgerEntryReversedEvent(
                reversalJournalId: $reversalJournal->id,
                reversedJournalId: $journalId,
                actorId:           $actorId,
                correlationId:     $correlationId,
            ));

            $this->auditService->record(new AuditEventData(
                action:        'ledger.entry.reversed',
                actorType:     'ADMIN',
                resourceType:  'journal',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $actorId,
                resourceId:    $reversalJournal->id,
                afterState:    [
                    'reversal_journal_id' => $reversalJournal->id,
                    'reversed_journal_id' => $journalId,
                ],
            ));

            Log::info('ledger.entries.reversed', [
                'reversal_journal_id' => $reversalJournal->id,
                'reversed_journal_id' => $journalId,
                'actor_id'            => $actorId,
                'correlation_id'      => $correlationId,
            ]);

            $this->metrics->increment('ledger.reversals.posted');
        });

        if ($lock !== null) {
            $this->idempotencyService->recordResponse(
                $lock,
                201,
                ['reversal_journal_id' => $reversalId],
            );
        }

        return $reversalId;
    }
}
