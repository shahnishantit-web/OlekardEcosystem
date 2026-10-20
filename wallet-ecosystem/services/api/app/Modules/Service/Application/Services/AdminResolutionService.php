<?php

declare(strict_types=1);

namespace App\Modules\Service\Application\Services;

use App\Modules\Service\Domain\Enums\ServiceTransactionStatus;
use App\Modules\Service\Domain\Exceptions\ServiceTransactionNotFoundException;
use App\Modules\Service\Domain\Exceptions\TransactionNotModifiableException;
use App\Modules\Service\Infrastructure\Models\ServiceTransactionModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;

final class AdminResolutionService
{
    public function __construct(
        private readonly ServiceOutcomeService $outcome,
        private readonly AuditServiceInterface $audit,
    ) {}

    public function resolve(
        string  $txId,
        string  $outcomeChoice,
        string  $rationale,
        string  $actorId,
        string  $correlationId,
        ?string $providerRef = null,
    ): ServiceTransactionModel {
        $tx = ServiceTransactionModel::where('id', $txId)->first();
        if ($tx === null) {
            throw new ServiceTransactionNotFoundException($txId);
        }

        if (! $tx->status->isResolvable()) {
            throw new TransactionNotModifiableException($txId, $tx->status->value);
        }

        $this->audit->record(new AuditEventData(
            action:        'service.admin.force_outcome',
            actorType:     'admin',
            resourceType:  'service_transaction',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $txId,
            afterState:    [
                'outcome'       => $outcomeChoice,
                'rationale'     => $rationale,
                'provider_ref'  => $providerRef,
            ],
        ));

        if ($outcomeChoice === 'success') {
            $this->outcome->handleSuccess($txId, $providerRef ?? '', $correlationId);
        } else {
            $this->outcome->handleFailure($txId, 'admin_forced_failure: '.$rationale, $correlationId);
        }

        $tx->refresh();
        return $tx;
    }
}
