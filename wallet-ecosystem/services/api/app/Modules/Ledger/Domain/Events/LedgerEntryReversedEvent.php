<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class LedgerEntryReversedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $reversalJournalId,
        private readonly string $reversedJournalId,
        ?string $actorId,
        string $correlationId,
    ) {
        parent::__construct($reversalJournalId, $actorId, $correlationId);
    }

    public function eventName(): string
    {
        return 'Ledger.Entry.Reversed';
    }

    public function aggregateType(): string
    {
        return 'Journal';
    }

    public function payload(): array
    {
        return [
            'journal_id'          => $this->aggregateId(),
            'reversed_journal_id' => $this->reversedJournalId,
            'actor_id'            => $this->actorId,
            'occurred_at'         => $this->occurredAt(),
        ];
    }
}
