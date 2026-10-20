<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts\Providers;

use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderCapabilitySet;

interface ProviderCapabilityInterface
{
    public function getCapabilities(): ProviderCapabilitySet;
}
