<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Contracts;

use App\Modules\Agent\Domain\ValueObjects\AgentKycPayload;
use App\Modules\Agent\Domain\ValueObjects\AgentKycResult;

interface AgentKycProviderContract
{
    public function verify(AgentKycPayload $payload): AgentKycResult;

    public function providerName(): string;
}
