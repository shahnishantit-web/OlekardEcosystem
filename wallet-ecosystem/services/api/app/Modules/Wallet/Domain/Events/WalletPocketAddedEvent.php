<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class WalletPocketAddedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $walletId,
        private readonly string $pocketId,
        private readonly string $currencyCode,
        private readonly string $ledgerAccountReference,
        ?string $actorId,
        string $correlationId,
    ) {
        parent::__construct($walletId, $actorId, $correlationId);
    }

    public function eventName(): string
    {
        return 'Wallet.Pocket.Added';
    }

    public function aggregateType(): string
    {
        return 'Wallet';
    }

    public function payload(): array
    {
        return [
            'wallet_id'                => $this->aggregateId(),
            'pocket_id'                => $this->pocketId,
            'currency_code'            => $this->currencyCode,
            'ledger_account_reference' => $this->ledgerAccountReference,
            'actor_id'                 => $this->actorId(),
            'correlation_id'           => $this->correlationId(),
        ];
    }
}
