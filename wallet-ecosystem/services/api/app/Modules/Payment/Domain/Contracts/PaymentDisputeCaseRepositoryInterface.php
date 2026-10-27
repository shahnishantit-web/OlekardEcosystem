<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Infrastructure\Models\PaymentDisputeCaseModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PaymentDisputeCaseRepositoryInterface
{
    public function findById(string $id): ?PaymentDisputeCaseModel;

    public function findByProviderRef(string $providerCode, string $providerDisputeReference): ?PaymentDisputeCaseModel;

    /**
     * @param array{
     *   status?: string,
     *   case_type?: string,
     *   requires_finance_review?: bool,
     *   deadline_before?: string,
     *   payment_intent_id?: string,
     *   payment_attempt_id?: string,
     *   page?: int,
     *   per_page?: int,
     * } $filters
     */
    public function listFiltered(array $filters): LengthAwarePaginator;

    public function save(PaymentDisputeCaseModel $model): void;
}
