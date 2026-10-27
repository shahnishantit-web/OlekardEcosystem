<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Infrastructure\Models\PaymentProviderModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface BankRepositoryInterface
{
    public function findById(string $id): ?PaymentProviderModel;

    public function findByCode(string $code): ?PaymentProviderModel;

    /**
     * @param array{status?: string, code?: string, display_name?: string} $filters
     * @return LengthAwarePaginator<PaymentProviderModel>
     */
    public function listPaginated(array $filters, int $perPage): LengthAwarePaginator;

    public function save(PaymentProviderModel $bank): PaymentProviderModel;
}
