<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class LedgerAccountRegisteredEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $ledgerAccountId,
        private readonly string $ledgerAccountReference,
        private readonly string $accountType,
        private readonly string $currencyCode,
        private readonly ?string $ownerType,
        ?string $actorId,
        string $correlationId,
    ) {
        parent::__construct($ledgerAccountId, $actorId, $correlationId);
    }

    public function eventName(): string
    {
        return 'Ledger.Account.Registered';
    }

    public function aggregateType(): string
    {
        return 'LedgerAccount';
    }

    public function payload(): array
    {
        return [
            'ledger_account_id'        => $this->aggregateId(),
            'ledger_account_reference' => $this->ledgerAccountReference,
            'account_type'             => $this->accountType,
            'currency_code'            => $this->currencyCode,
            'owner_type'               => $this->ownerType,
            'actor_id'                 => $this->actorId,
            'occurred_at'              => $this->occurredAt(),
        ];
    }
}
