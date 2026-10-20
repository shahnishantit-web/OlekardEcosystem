<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class LedgerEntryPostedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $journalId,
        private readonly string $entryType,
        private readonly string $currencyCode,
        private readonly ?string $transactionReference,
        ?string $actorId,
        string $correlationId,
    ) {
        parent::__construct($journalId, $actorId, $correlationId);
    }

    public function eventName(): string
    {
        return 'Ledger.Entry.Posted';
    }

    public function aggregateType(): string
    {
        return 'Journal';
    }

    public function payload(): array
    {
        return [
            'journal_id'            => $this->aggregateId(),
            'entry_type'            => $this->entryType,
            'currency_code'         => $this->currencyCode,
            'transaction_reference' => $this->transactionReference,
            'actor_id'              => $this->actorId,
            'occurred_at'           => $this->occurredAt(),
        ];
    }
}
