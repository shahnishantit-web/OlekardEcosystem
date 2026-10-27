<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Infrastructure\Models\PaymentQuoteModel;

interface PaymentQuoteRepositoryInterface
{
    public function save(PaymentQuoteModel $quote): void;

    public function findById(string $id): ?PaymentQuoteModel;

    public function findActiveForIntent(string $intentId): ?PaymentQuoteModel;

    /** @return PaymentQuoteModel[] */
    public function findAllForIntent(string $intentId): array;
}
