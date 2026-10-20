<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Domain\Contracts;

interface FxRateProviderInterface
{
    /** @return array{rate:string,provider:string,observed_at:string} Authoritative observation; throws when unconfigured/unavailable. */
    public function quote(string $from, string $to): array;
}
