<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Infrastructure\Providers;

use App\Modules\MultiCurrency\Domain\Contracts\FxRateProviderInterface;

final class UnavailableFxRateProvider implements FxRateProviderInterface
{
    public function quote(string $from, string $to): array
    {
        throw new \RuntimeException('FX provider is not configured. Provider selection remains pending.');
    }
}
