<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Domain\Contracts;

use App\Modules\MultiCurrency\Infrastructure\Models\CurrencyModel;
use Illuminate\Support\Collection;

interface CurrencyRepositoryInterface
{
    public function findByCode(string $code): ?CurrencyModel;

    public function listActive(): Collection;

    public function listAll(): Collection;

    public function upsert(array $attributes): CurrencyModel;
}
