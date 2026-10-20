<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain\Contracts;

use App\Modules\Platform\Domain\Contracts\Providers\ProviderAdapterInterface;

interface ProviderHealthInventoryInterface
{
    /** @return array<array{id:string,type:string,adapter:?ProviderAdapterInterface}> */
    public function targets(): array;
}
