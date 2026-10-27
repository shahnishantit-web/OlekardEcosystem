<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Domain\Enums\PayoutBeneficiaryStatus;
use App\Modules\Payment\Infrastructure\Models\PayoutBeneficiaryModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PayoutBeneficiaryRepositoryInterface
{
    public function findById(string $id): ?PayoutBeneficiaryModel;

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator;

    public function save(PayoutBeneficiaryModel $model): void;
}
