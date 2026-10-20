<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Domain\Adapters;

interface ProviderFileAdapterInterface
{
    /**
     * Parse a raw file stream into an array of transaction DTOs.
     *
     * @param  resource  $fileStream
     * @return ProviderTransactionDto[]
     */
    public function parse(mixed $fileStream, string $mimeType): array;
}
