<?php

declare(strict_types=1);

namespace App\Modules\Ops\Domain\Contracts;

interface IntegrationHealthHistoryInterface
{
    public function history(string $integrationId): array;
}
