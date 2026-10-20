<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Domain\ValueObjects\OptionsQuery;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderModel;

interface PaymentProviderRepositoryInterface
{
    public function findByCode(string $code): ?PaymentProviderModel;

    /** @return PaymentProviderAccountModel[] */
    public function findActiveForContext(OptionsQuery $query): array;

    public function findProviderAccount(string $providerAccountId): ?PaymentProviderAccountModel;
}
