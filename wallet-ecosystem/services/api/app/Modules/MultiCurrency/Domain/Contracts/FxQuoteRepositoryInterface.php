<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Domain\Contracts;

use App\Modules\MultiCurrency\Infrastructure\Models\FxQuoteModel;

interface FxQuoteRepositoryInterface
{
    public function findById(string $id): ?FxQuoteModel;

    public function findByIdempotencyKey(string $key): ?FxQuoteModel;

    public function save(FxQuoteModel $model): void;
}
