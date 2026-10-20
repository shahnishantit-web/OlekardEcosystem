<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Domain\ValueObjects;

final readonly class KycStorageResult
{
    public function __construct(
        public string $storageKey,
        public int $sizeBytes,
        public string $mimeType,
    ) {}
}
