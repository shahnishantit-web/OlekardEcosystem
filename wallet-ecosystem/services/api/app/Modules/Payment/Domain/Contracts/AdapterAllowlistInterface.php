<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

interface AdapterAllowlistInterface
{
    public function isAllowed(string $adapterIdentifier): bool;

    /** @return string[] */
    public function getAllowedIdentifiers(): array;
}
