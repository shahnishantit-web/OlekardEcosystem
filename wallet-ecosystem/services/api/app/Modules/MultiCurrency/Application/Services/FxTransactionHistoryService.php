<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Application\Services;

use App\Modules\MultiCurrency\Domain\Contracts\FxTransactionRepositoryInterface;
use App\Modules\MultiCurrency\Infrastructure\Models\FxTransactionModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Pagination\LengthAwarePaginator;

final class FxTransactionHistoryService
{
    public function __construct(
        private readonly FxTransactionRepositoryInterface $fxTransactions,
        private readonly AuditServiceInterface            $audit,
    ) {}

    public function list(string $customerId, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->fxTransactions->paginateByCustomer($customerId, $filters, $perPage);
    }

    public function get(string $customerId, string $id): FxTransactionModel
    {
        $tx = $this->fxTransactions->findById($id);
        if ($tx === null || $tx->customer_id !== $customerId) {
            throw new \DomainException("FX transaction [{$id}] not found.");
        }

        $this->audit->record(new AuditEventData(
            action:        'multi_currency.fx_transaction.viewed',
            actorId:       $customerId,
            actorType:     'CUSTOMER',
            resourceType:  'fx_transaction',
            resourceId:    $id,
            correlationId: request()->header('X-Correlation-ID', ''),
            outcome:       'success',
        ));

        return $tx;
    }
}
